<?php
/**
 * Bounded CSV serialization of a single completed audit.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

use ContentLatch\Application\AuditPresentation;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Domain\RuleSeverity;

final class AuditCsvExporter
{
    public const BATCH_SIZE = 500;

    public const HEADERS = array(
        'Post ID', 'Post Title', 'Post Type', 'Post Status', 'Field Name',
        'Rule Name', 'Severity', 'Validation Message', 'Edit URL',
    );

    public function __construct(private AuditStoreInterface $store, private AuditExportMetadata $metadata)
    {
    }

    /**
     * Write at the stream's current position; the caller owns and closes it.
     * Returns the finding count only after every batch and stream flush succeed.
     * Exceptions propagate; bytes already written cannot be rolled back. An HTTP
     * caller should stage into a private temporary stream before sending headers
     * if it must avoid delivering partial exports on generation failures.
     *
     * @param resource $stream Writable, blocking stream.
     * @throws AuditException On an invalid run, invalid UTF-8, or failed output.
     */
    public function export(int $runId, mixed $stream): int
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new AuditException('Audit CSV output must be a writable stream.');
        }
        $mode = stream_get_meta_data($stream)['mode'];
        if (strpbrk($mode, 'waxc+') === false) {
            throw new AuditException('Audit CSV output must be a writable stream.');
        }

        $run = $runId > 0 ? $this->store->findRun($runId) : null;
        if ($run === null || $run->id !== $runId || $run->status !== AuditRunStatus::Complete) {
            throw new AuditException('Only completed audits can be exported.');
        }

        // One CSV record at a time, so short writes can be detected/retried
        // without accumulating the export or silently losing part of a record.
        $record = fopen('php://memory', 'w+b');
        if ($record === false) {
            throw new AuditException('Could not prepare audit CSV output.');
        }

        try {
            $this->writeAll($stream, "\xEF\xBB\xBF");
            $this->writeRecord($stream, $record, self::HEADERS);
            $afterId = 0;
            $count = 0;

            while (true) {
                $findings = $this->store->findFindingsAfterId($runId, $afterId, self::BATCH_SIZE);
                if ($findings === array()) {
                    break;
                }
                if (count($findings) > self::BATCH_SIZE) {
                    throw new AuditException('Invalid audit finding batch.');
                }

                // Cache only the current batch, including misses. Neither the
                // number of posts nor the number of rules can grow with the file.
                $posts = array();
                $rules = array();
                foreach ($findings as $finding) {
                    if ($finding->runId !== $runId || $finding->id <= $afterId) {
                        throw new AuditException('Invalid audit finding cursor.');
                    }
                    if (!array_key_exists($finding->postId, $posts)) {
                        $posts[$finding->postId] = $this->metadata->post($finding->postId);
                    }
                    $ruleId = (string) $finding->ruleId;
                    if (!array_key_exists($ruleId, $rules)) {
                        $rules[$ruleId] = $this->metadata->rule($finding->ruleId);
                    }
                    $post = $posts[$finding->postId];
                    $rule = $rules[$ruleId];
                    $title = $post === null
                        ? AuditPresentation::postTitle('')
                        : ($post['title'] !== '' ? $post['title'] : __('(no title)', 'contentlatch'));
                    $ruleName = AuditPresentation::ruleName($rule, $finding->ruleId);
                    if ($rule === null) {
                        $ruleName .= ' (#' . $ruleId . ')';
                    }

                    $text = array(
                        $title,
                        $finding->postType,
                        $post['status'] ?? '',
                        AuditPresentation::fieldLabel($rule, $finding->fieldKey),
                        $ruleName,
                        $finding->severity === RuleSeverity::Fail ? 'blocking' : 'warning',
                        $finding->message,
                        $post['edit_url'] ?? '',
                    );
                    $cells = array_map(self::safeText(...), $text);
                    // The sole numeric column is never apostrophe-prefixed.
                    array_unshift($cells, (string) $finding->postId);
                    $this->writeRecord($stream, $record, $cells);
                    $afterId = $finding->id;
                    $count++;
                }
                unset($posts, $rules, $findings);
            }

            if (!@fflush($stream)) {
                throw new AuditException('Could not flush audit CSV output.');
            }

            return $count;
        } finally {
            fclose($record);
        }
    }

    /**
     * Protect spreadsheet imports without removing or normalizing the original
     * text. Prefix risky text with an ASCII apostrophe BEFORE any whitespace.
     * Inspect Unicode separators, controls/format marks and combining marks, and
     * compatibility forms of = + - @ (including full-width/small/super/subscript).
     * Leading controls are guarded even without an immediately visible formula.
     * Quoting is handled separately by fputcsv; quoting alone is not protection.
     * This is an import policy, not a guarantee after spreadsheet editing or
     * re-saving, where applications may discard text markers (OWASP CSV Injection).
     */
    private static function safeText(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new AuditException('Audit CSV text must be valid UTF-8.');
        }

        if (
            preg_match('/\A[\p{Z}\p{C}\p{M}]*[=+\-@＝＋－＠﹦﹢﹣﹫⁼₌⁺₊⁻₋−﬩]/u', $value) === 1
            || preg_match('/\A[\p{Z}\p{M}]*\p{C}/u', $value) === 1
        ) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param resource $stream
     * @param resource $record
     * @param string[] $cells
     */
    private function writeRecord(mixed $stream, mixed $record, array $cells): void
    {
        rewind($record);
        ftruncate($record, 0);
        if (fputcsv($record, $cells, ',', '"', '', "\r\n") === false) {
            throw new AuditException('Could not serialize audit CSV record.');
        }
        rewind($record);
        $bytes = stream_get_contents($record);
        if ($bytes === false) {
            throw new AuditException('Could not read audit CSV record.');
        }
        $this->writeAll($stream, $bytes);
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            // Convert stream warnings into a safe exception; never emit them
            // into a future HTTP response. A zero write must not spin forever.
            $written = @fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new AuditException('Could not write audit CSV output.');
            }
            $offset += $written;
        }
    }
}
