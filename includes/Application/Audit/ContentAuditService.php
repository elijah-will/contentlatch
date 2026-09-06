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
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\ContentEvaluation;
use ContentGuard\Domain\ContentStatus;
use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfStoredValueProvider;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
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
        private AcfFieldCatalog $catalog,
        private mixed $providerFactory,
        private mixed $warmMeta,
        private mixed $now,
        private int $batchSize = self::BATCH_SIZE,
    ) {
    }

    public static function wordpress(RuleRepositoryInterface $rules): self
    {
        return new self(
            new WpAuditStore(),
            new WpAuditPostScanner(),
            new WpAuditLock(),
            $rules,
            new ContentEvaluator($rules, RuleEngine::v1()),
            new AcfFieldCatalog(),
            static function (int $postId, string $postType, array $fieldTypes): AcfStoredValueProvider {
                unset($postType);

                return new AcfStoredValueProvider($postId, new AcfValueNormalizer(), $fieldTypes);
            },
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

            throw new AuditException('Another audit start is already in progress.');
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
            throw new AuditException('This audit run is no longer active.');
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
            throw new AuditException('This audit run is no longer active.');
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
    public function listRecentRuns(int $limit = 20): array
    {
        return $this->store->findRecentRuns(max(1, $limit));
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
            throw new AuditException('Audit value provider is not configured.');
        }

        $provider = $factory($post->id, $post->postType, $this->fieldTypesFor($post->postType));
        if (!$provider instanceof FieldValueProviderInterface) {
            throw new AuditException('Audit value provider is invalid.');
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
                $keys[$condition->field->key] = true;
            }
            foreach ($rule->validations as $validation) {
                $keys[$validation->field->key] = true;
            }
        }

        return $keys;
    }

    /**
     * @return AuditFinding[]
     */
    private function findingsFrom(int $runId, AuditPost $post, ContentEvaluation $evaluation): array
    {
        $findings = array();

        foreach ($evaluation->results as $result) {
            if (!$result instanceof EvaluationResult) {
                continue;
            }

            if (!$result->isFailed() && !$result->isWarning()) {
                continue;
            }

            $findings[] = new AuditFinding(
                0,
                $runId,
                $post->id,
                $post->postType,
                $result->ruleId,
                (string) $result->fieldId,
                (string) ($result->context['validation_id'] ?? ''),
                $result->code,
                $result->severity,
                $result->message,
                $this->datetime(),
            );
        }

        return $findings;
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
            throw new AuditException('Invalid audit run.');
        }

        $run = $this->store->findRun($runId);
        if ($run === null) {
            throw new AuditException('Audit run not found.');
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
