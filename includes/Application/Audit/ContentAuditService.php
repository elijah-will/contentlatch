<?php
/**
 * Site-wide content audit orchestration.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\Exception\AuditException;
use ContentGuard\Application\I18n;
use ContentGuard\Application\Integration\FieldCatalog;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\ContentEvaluation;
use ContentGuard\Domain\ContentStatus;
use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\WordPress\WpAuditLock;
use ContentGuard\Infrastructure\WordPress\WpAuditPostScanner;
use ContentGuard\Infrastructure\WordPress\WpAuditStore;
use Throwable;

final class ContentAuditService
{
    public const BATCH_SIZE           = 100;
    public const STALE_AFTER_SECONDS  = 900;
    public const NONCE_ACTION         = 'contentguard_audit';
    public const AUDITED_STATUSES     = array('publish', 'private');

    /**
     * @var array<string, array<string, string>>
     */
    private array $fieldTypeCache = array();

    /**
     * @param callable(int $postId, string $postType, array<string, string> $fieldTypes): FieldValueProviderInterface $providerFactory
     * @param callable(int[]): void $warmMeta
     * @param callable(): int $now
     */
    public function __construct(
        private AuditStoreInterface $store,
        private AuditPostScanner $scanner,
        private AuditLockInterface $lock,
        private RuleRepositoryInterface $rules,
        private ContentEvaluator $evaluator,
        private FieldCatalog $catalog,
        private mixed $providerFactory,
        private mixed $warmMeta,
        private mixed $now,
        private int $batchSize = self::BATCH_SIZE,
    ) {
    }

    /**
     * @param callable(int $postId, string $postType, array<string, string> $fieldTypes): FieldValueProviderInterface $providerFactory
     */
    public static function wordpress(RuleRepositoryInterface $rules, FieldCatalog $catalog, mixed $providerFactory): self
    {
        return new self(
            new WpAuditStore(),
            new WpAuditPostScanner(),
            new WpAuditLock(),
            $rules,
            new ContentEvaluator($rules, RuleEngine::v1()),
            $catalog,
            $providerFactory,
            static function (array $ids): void {
                if ($ids !== array() && function_exists('update_postmeta_cache')) {
                    update_postmeta_cache($ids);
                }
            },
            static fn (): int => time(),
        );
    }

    public function start(int $actorUserId): AuditRun
    {
        $active = $this->store->findActiveRun();
        if ($active !== null) {
            if (!$this->isStale($active)) {
                return $active;
            }
            $this->failStale($active);
        }

        if (!$this->lock->acquire()) {
            $again = $this->store->findActiveRun();
            if ($again !== null) {
                if ($this->isStale($again)) {
                    $this->failStale($again);

                    return $this->start($actorUserId);
                }

                return $again;
            }

            throw new AuditException(I18n::translate('Another audit start is already in progress.'));
        }

        try {
            $existing = $this->store->findActiveRun();
            if ($existing !== null) {
                $this->lock->release();

                return $existing;
            }

            $types = $this->rules->findActivePostTypes();
            $total = $types === array() ? 0 : $this->scanner->count($types, self::AUDITED_STATUSES);
            $run   = $this->store->insertRun($actorUserId, $types, $total, $this->datetime());

            if ($total === 0) {
                return $this->complete($run);
            }

            return $run;
        } catch (Throwable $exception) {
            $this->lock->release();
            throw $exception;
        }
    }

    public function processBatch(int $runId): AuditRun
    {
        $run = $this->requireRun($runId);
        if (!$run->isActive()) {
            throw new AuditException(I18n::translate('This audit run is no longer active.'));
        }

        try {
            return $this->processBatchUnsafe($run);
        } catch (AuditException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->log($exception);

            return $this->fail($run, 'Audit batch failed.');
        }
    }

    public function cancel(int $runId): AuditRun
    {
        $run = $this->requireRun($runId);
        if (!$run->isActive()) {
            throw new AuditException(I18n::translate('This audit run is no longer active.'));
        }

        $cancelled = $this->store->saveRun(
            $run->withStatus(AuditRunStatus::Cancelled, $this->datetime())
        );
        $this->lock->release();

        return $cancelled;
    }

    public function getRun(int $runId): AuditRun
    {
        return $this->requireRun($runId);
    }

    public function getActiveRun(): ?AuditRun
    {
        $active = $this->store->findActiveRun();
        if ($active !== null && $this->isStale($active)) {
            $this->failStale($active);

            return null;
        }

        return $active;
    }

    public function getLatestCompleteRun(): ?AuditRun
    {
        return $this->store->findLatestCompleteRun();
    }

    public function getLatestRun(): ?AuditRun
    {
        return $this->store->findLatestRun();
    }

    /**
     * @return AuditFinding[]
     */
    public function getFindings(int $runId, ?string $severity = null): array
    {
        $this->requireRun($runId);

        return $this->store->findFindings($runId, $severity);
    }

    /**
     * Blocking findings for one post in a completed audit run.
     *
     * Never returns findings that belong to a different post.
     *
     * @return AuditFinding[]
     */
    public function blockingFindingsForPost(int $runId, int $postId): array
    {
        if ($runId <= 0 || $postId <= 0) {
            return array();
        }

        $run = $this->store->findRun($runId);
        if ($run === null || $run->status !== AuditRunStatus::Complete) {
            return array();
        }

        $matches = array();
        foreach ($this->store->findFindings($runId, 'fail') as $finding) {
            if ($finding->postId === $postId) {
                $matches[] = $finding;
            }
        }

        return $matches;
    }

    /**
     * Live stored-value evaluation for the editor notice refresh.
     *
     * Does not read or write audit findings.
     */
    public function evaluateStoredPost(int $postId, string $postType): ?ContentEvaluation
    {
        if ($postId <= 0 || $postType === '') {
            return null;
        }

        try {
            return $this->evaluatePost(new AuditPost($postId, $postType));
        } catch (AuditException) {
            return null;
        }
    }

    /**
     * @return AuditFinding[]
     */
    public function queryFindings(AuditFindingQuery $query): array
    {
        $this->requireRun($query->runId);

        return $this->store->queryFindings($query);
    }

    public function countFindings(AuditFindingQuery $query): int
    {
        $this->requireRun($query->runId);

        return $this->store->countFindings($query);
    }

    public function countDistinctPosts(AuditFindingQuery $query): int
    {
        $this->requireRun($query->runId);

        return $this->store->countDistinctPosts($query);
    }

    /**
     * @return array{fail: int, warning: int}
     */
    public function countFindingsBySeverity(int $runId): array
    {
        $this->requireRun($runId);

        return $this->store->countFindingsBySeverity($runId);
    }

    /**
     * @return AuditRuleImpact[]
     */
    public function countFindingsByRule(int $runId): array
    {
        $this->requireRun($runId);

        return $this->store->countFindingsByRule($runId);
    }

    /**
     * @return AuditRun[]
     */
    public function listRecentRuns(int $limit = 20, int $offset = 0): array
    {
        return $this->store->findRecentRuns(max(1, $limit), max(0, $offset));
    }

    public function countRuns(): int
    {
        return $this->store->countRuns();
    }

    private function processBatchUnsafe(AuditRun $run): AuditRun
    {
        if ($run->status === AuditRunStatus::Pending) {
            $run = $this->store->saveRun(
                new AuditRun(
                    $run->id,
                    AuditRunStatus::Running,
                    $run->startedAt,
                    null,
                    $this->datetime(),
                    $run->postsScanned,
                    $run->postsPassed,
                    $run->postsWarned,
                    $run->postsFailed,
                    $run->postsNotEvaluated,
                    $run->postsTotal,
                    $run->cursor,
                    $run->actorUserId,
                    $run->postTypes,
                    null,
                )
            );
        }

        $posts = $this->scanner->scan(
            $run->postTypes,
            self::AUDITED_STATUSES,
            $run->cursor,
            $this->batchSize
        );

        if ($posts === array()) {
            return $this->complete($run);
        }

        $ids = array_map(static fn (AuditPost $post): int => $post->id, $posts);
        $warm = $this->warmMeta;
        if (is_callable($warm)) {
            $warm($ids);
        }

        $findings = array();
        $passed   = 0;
        $warned   = 0;
        $failed   = 0;
        $skipped  = 0;

        foreach ($posts as $post) {
            $evaluation = $this->evaluatePost($post);
            foreach ($this->findingsFrom($run->id, $post, $evaluation) as $finding) {
                $findings[] = $finding;
            }

            match ($evaluation->status) {
                ContentStatus::Failed       => ++$failed,
                ContentStatus::Warning      => ++$warned,
                ContentStatus::Passed       => ++$passed,
                ContentStatus::NotEvaluated => ++$skipped,
            };
        }

        $this->store->replaceFindingsForPosts($run->id, $ids, $findings);

        $last = $posts[array_key_last($posts)];
        $updated = new AuditRun(
            $run->id,
            AuditRunStatus::Running,
            $run->startedAt,
            null,
            $this->datetime(),
            $run->postsScanned + count($posts),
            $run->postsPassed + $passed,
            $run->postsWarned + $warned,
            $run->postsFailed + $failed,
            $run->postsNotEvaluated + $skipped,
            $run->postsTotal,
            $last->id,
            $run->actorUserId,
            $run->postTypes,
            null,
        );

        $saved = $this->store->saveRun($updated);

        if (count($posts) < $this->batchSize) {
            return $this->complete($saved);
        }

        return $saved;
    }

    private function evaluatePost(AuditPost $post): ContentEvaluation
    {
        $factory = $this->providerFactory;
        if (!is_callable($factory)) {
            throw new AuditException(I18n::translate('Audit value provider is not configured.'));
        }

        $provider = $factory($post->id, $post->postType, $this->fieldTypesFor($post->postType));
        if (!$provider instanceof FieldValueProviderInterface) {
            throw new AuditException(I18n::translate('Audit value provider is invalid.'));
        }

        return $this->evaluator->evaluate($post->id, $post->postType, $provider);
    }

    /**
     * @return array<string, string>
     */
    private function fieldTypesFor(string $postType): array
    {
        if (!isset($this->fieldTypeCache[$postType])) {
            $this->fieldTypeCache[$postType] = array_intersect_key(
                $this->catalog->fieldTypesForPostType($postType),
                $this->referencedFieldKeys($this->rules->findActiveForPostType($postType))
            );
        }

        return $this->fieldTypeCache[$postType];
    }

    /**
     * @param Rule[] $rules
     * @return array<string, true>
     */
    private function referencedFieldKeys(array $rules): array
    {
        $keys = array();

        foreach ($rules as $rule) {
            foreach ($rule->conditions as $condition) {
                $keys[$condition->field->resolutionId()] = true;
            }
            foreach ($rule->validations as $validation) {
                $keys[$validation->field->resolutionId()] = true;
            }
        }

        return $keys;
    }

    /**
     * @return AuditFinding[]
     */
    private function findingsFrom(int $runId, AuditPost $post, ContentEvaluation $evaluation): array
    {
        $buckets = array();
        $order   = array();

        foreach ($evaluation->results as $result) {
            if (!$result instanceof EvaluationResult) {
                continue;
            }

            if (!$result->isFailed() && !$result->isWarning()) {
                continue;
            }

            $key = implode(
                "\n",
                array(
                    (string) $result->ruleId,
                    (string) $result->fieldId,
                    (string) ($result->context['validation_id'] ?? ''),
                    $result->severity->value,
                )
            );
            if (!isset($buckets[$key])) {
                $buckets[$key] = array();
                $order[]       = $key;
            }

            $buckets[$key][] = $result;
        }

        $findings = array();
        foreach ($order as $key) {
            $results  = $buckets[$key];
            $first    = $results[0];
            $findings[] = new AuditFinding(
                0,
                $runId,
                $post->id,
                $post->postType,
                $first->ruleId,
                (string) $first->fieldId,
                (string) ($first->context['validation_id'] ?? ''),
                $first->code,
                $first->severity,
                $this->snapshotMessage($results),
                $this->datetime(),
                AuditRepeaterCoordinates::contextFromCells(
                    AuditRepeaterCoordinates::cellsFromResults($results)
                ),
            );
        }

        return $findings;
    }

    /**
     * @param EvaluationResult[] $results
     */
    private function snapshotMessage(array $results): string
    {
        $first = $results[0];
        $base  = $this->instanceMessage($first);
        $cells = AuditRepeaterCoordinates::cellsFromResults($results);
        if ($cells !== array()) {
            return AuditRepeaterCoordinates::formatSnapshot($base, $cells);
        }

        if (count($results) === 1) {
            $row         = $first->context['display_row'] ?? null;
            $row         = is_int($row) || (is_numeric($row) && (int) $row > 0) ? (int) $row : 0;
            $layoutLabel = $this->layoutLabel($first);
            if ($row > 0 && $layoutLabel !== '') {
                return I18n::sprintf(
                    I18n::translate('%s in %s row %d.'),
                    rtrim($base, '.'),
                    $layoutLabel,
                    $row
                );
            }

            if ($row > 0) {
                return I18n::sprintf(I18n::translate('%s in row %d.'), rtrim($base, '.'), $row);
            }

            return $base;
        }

        $rows = array();
        foreach ($results as $result) {
            $row = $result->context['display_row'] ?? null;
            if (is_int($row) || (is_numeric($row) && (int) $row > 0)) {
                $rows[] = (int) $row;
            }
        }
        $rows = array_values(array_unique($rows));

        if ($rows === array()) {
            return $base;
        }

        $layoutLabel = $this->layoutLabel($first);
        if ($layoutLabel !== '') {
            return I18n::sprintf(
                I18n::translate('%s in %d %s rows (rows %s).'),
                rtrim($base, '.'),
                count($rows),
                $layoutLabel,
                implode(', ', $rows)
            );
        }

        return I18n::sprintf(
            I18n::translate('%s in %d rows (rows %s).'),
            rtrim($base, '.'),
            count($rows),
            implode(', ', $rows)
        );
    }

    private function instanceMessage(EvaluationResult $result): string
    {
        if ($result->code === 'no_rows' && $result->message !== '') {
            return $result->message;
        }

        $stockRequired = 'This field is required.';
        if ($result->message !== ''
            && $result->message !== $stockRequired
            && $result->message !== I18n::translate($stockRequired)
        ) {
            return $result->message;
        }

        $hasRowContext = isset($result->context['display_row'])
            || AuditRepeaterCoordinates::instanceChain($result->context) !== array();
        if ($hasRowContext && $result->code === 'required') {
            $label = $this->leafLabel((string) ($result->context['field_label'] ?? ''));
            if ($label !== '') {
                return I18n::sprintf(I18n::translate('%s is required.'), $label);
            }
        }

        return $result->message !== '' ? $result->message : I18n::translate($stockRequired);
    }

    private function layoutLabel(EvaluationResult $result): string
    {
        $layout = trim((string) ($result->context['layout'] ?? ''));
        if ($layout === '') {
            return '';
        }

        $parts = preg_split('/\s*→\s*/u', (string) ($result->context['field_label'] ?? '')) ?: array();
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));

        return $parts[1] ?? $layout;
    }

    private function leafLabel(string $breadcrumb): string
    {
        $parts = preg_split('/\s*→\s*/u', $breadcrumb) ?: array();
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));

        return $parts !== array() ? (string) $parts[count($parts) - 1] : '';
    }

    private function complete(AuditRun $run): AuditRun
    {
        $completed = $this->store->saveRun(
            $run->withStatus(AuditRunStatus::Complete, $this->datetime())
        );
        $this->lock->release();

        return $completed;
    }

    private function fail(AuditRun $run, string $message): AuditRun
    {
        $failed = $this->store->saveRun(
            $run->withStatus(AuditRunStatus::Failed, $this->datetime(), $message)
        );
        $this->lock->release();

        return $failed;
    }

    private function failStale(AuditRun $run): void
    {
        $this->fail($run, 'Audit run timed out.');
    }

    private function isStale(AuditRun $run): bool
    {
        $heartbeat = strtotime($run->heartbeatAt);
        if ($heartbeat === false) {
            return true;
        }

        return ($this->timestamp() - $heartbeat) > self::STALE_AFTER_SECONDS;
    }

    private function requireRun(int $runId): AuditRun
    {
        if ($runId <= 0) {
            throw new AuditException(I18n::translate('Invalid audit run.'));
        }

        $run = $this->store->findRun($runId);
        if ($run === null) {
            throw new AuditException(I18n::translate('Audit run not found.'));
        }

        return $run;
    }

    private function datetime(): string
    {
        return gmdate('Y-m-d H:i:s', $this->timestamp());
    }

    private function timestamp(): int
    {
        $now = $this->now;

        return is_callable($now) ? (int) $now() : time();
    }

    private function log(Throwable $exception): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('ContentGuard audit failed: ' . $exception->getMessage());
        }
    }
}
