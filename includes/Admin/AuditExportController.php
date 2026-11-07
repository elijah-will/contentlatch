<?php
/**
 * Authenticated download of the latest completed Content Audit CSV.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Admin;

defined('ABSPATH') || exit;

use ContentLatch\Application\Audit\AuditCsvExporter;
use ContentLatch\Application\Audit\AuditRun;
use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Application\Audit\ContentAuditService;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Infrastructure\WordPress\Capabilities;
use ContentLatch\Infrastructure\WordPress\WpAuditExportMetadata;
use ContentLatch\Infrastructure\WordPress\WpAuditStore;
use Throwable;

final class AuditExportController
{
    public const ACTION       = 'contentlatch_audit_export_csv';
    public const NONCE_ACTION = 'contentlatch_audit_export_csv';

    /**
     * Keep at most 2MB in memory. Beyond that, PHP creates a private named
     * file (typically phpXXXXXX, mode 0600) in the system temp directory,
     * outside the web root and the uploads directory. PHP removes that file
     * when the stream is closed or the request ends. Abnormal process
     * termination can leave it behind. php://memory would retain the whole CSV.
     */
    private const STAGING_URI = 'php://temp/maxmemory:2097152';

    private bool $downloadStarted = false;

    /**
     * @param callable(): bool       $canManage
     * @param callable(string): bool $verifyNonce
     * @param callable(): void|null  $finishResponse   Test seam. Production exits.
     * @param callable(): int|null   $outputBufferFloor Test seam. Production clears every buffer.
     */
    public function __construct(
        private ContentAuditService $audit,
        private AuditCsvExporter $exporter,
        private mixed $canManage,
        private mixed $verifyNonce,
        private mixed $finishResponse = null,
        private mixed $outputBufferFloor = null,
    ) {
    }

    public static function register(ContentAuditService $audit, RuleRepositoryInterface $rules): void
    {
        $controller = new self(
            $audit,
            new AuditCsvExporter(new WpAuditStore(), new WpAuditExportMetadata($rules)),
            array(Capabilities::class, 'currentUserCanManage'),
            static function (string $nonce): bool {
                return wp_verify_nonce($nonce, self::NONCE_ACTION) !== false;
            }
        );

        add_action('admin_post_' . self::ACTION, array($controller, 'export'));
    }

    public function export(): void
    {
        $this->downloadStarted = false;
        $stream                = null;

        try {
            $filename = $this->stage($stream);
        } catch (Throwable $exception) {
            $this->log($exception);
            $this->closeStream($stream);
            $this->reject(__('Could not export the ContentLatch audit.', 'contentlatch'), 500);

            return;
        }

        if ($filename === null) {
            $this->closeStream($stream);

            return;
        }

        try {
            $this->send($stream, $filename);
        } catch (Throwable $exception) {
            $this->log($exception);
            $this->closeStream($stream);
            if ($this->downloadStarted) {
                $this->endResponse();

                return;
            }
            $this->reject(__('Could not export the ContentLatch audit.', 'contentlatch'), 500);

            return;
        }

        $this->closeStream($stream);
        $this->endResponse();
    }

    /**
     * Authorize, pin the latest completed run, and finish the CSV before any
     * download header is sent. Returns null when the response was already rejected.
     *
     * @param resource|null $stream
     */
    private function stage(mixed &$stream): ?string
    {
        $denied = $this->authorizationFailure();
        if ($denied !== null) {
            $this->reject($denied['message'], $denied['status']);

            return null;
        }

        // WpAuditStore::findLatestCompleteRun() returns null both when no
        // completed audit exists and when that query fails, because it does
        // not surface last_error. A failed finding read still throws from
        // the exporter, so it is not delivered as an empty file.
        $run = $this->audit->getLatestCompleteRun();
        if (!$run instanceof AuditRun || $run->status !== AuditRunStatus::Complete || $run->id <= 0) {
            $this->reject(__('No completed ContentLatch audit is available to export.', 'contentlatch'), 400);

            return null;
        }

        // The id and filename stay pinned to this run. Request filters, pagination,
        // and historical run ids are never read. The exporter's count, not a
        // separate COUNT query, decides whether this run has findings.
        $runId    = $run->id;
        $filename = $this->downloadFilename($run);

        $opened = $this->openStagingStream();
        if ($opened === false) {
            $this->reject(__('Could not export the ContentLatch audit.', 'contentlatch'), 500);

            return null;
        }
        $stream = $opened;

        $exported = $this->exporter->export($runId, $stream);
        if ($exported < 1) {
            $this->closeStream($stream);
            $this->reject(
                __('The latest completed ContentLatch audit has no findings to export.', 'contentlatch'),
                400
            );

            return null;
        }

        return $filename;
    }

    /**
     * Capability is checked before the nonce is read. No other request field is read.
     *
     * @return array{message: string, status: int}|null
     */
    private function authorizationFailure(): ?array
    {
        $canManage = $this->canManage;
        if (!is_callable($canManage) || !$canManage()) {
            return array(
                'message' => __('You are not allowed to export ContentLatch audits.', 'contentlatch'),
                'status'  => 403,
            );
        }

        $raw   = $_REQUEST['_wpnonce'] ?? null;
        $nonce = is_scalar($raw) ? sanitize_text_field(wp_unslash((string) $raw)) : '';
        if ($nonce === '') {
            return array(
                'message' => __('Missing audit export nonce.', 'contentlatch'),
                'status'  => 403,
            );
        }

        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($nonce)) {
            return array(
                'message' => __('Invalid audit export nonce.', 'contentlatch'),
                'status'  => 403,
            );
        }

        return null;
    }

    /** @param resource $stream */
    private function send(mixed $stream, string $filename): void
    {
        if (!is_resource($stream) || !rewind($stream)) {
            throw new AuditException('Could not read audit CSV output.');
        }

        if ($this->headersAlreadySent()) {
            $this->downloadStarted = true;
            throw new AuditException('Could not write audit CSV output.');
        }

        $this->discardBufferedOutput();

        $size = null;
        $stat = fstat($stream);
        if (is_array($stat) && isset($stat['size']) && is_int($stat['size']) && $stat['size'] >= 0) {
            $size = $stat['size'];
        }

        $this->downloadStarted = true;
        $this->sendDownloadHeaders($filename, $size);
        $this->writeOutput($stream);
    }

    private function sendDownloadHeaders(string $filename, ?int $size): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        if ($size !== null) {
            header('Content-Length: ' . $size);
        }
    }

    /** @param resource $stream */
    private function writeOutput(mixed $stream): void
    {
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new AuditException('Could not write audit CSV output.');
        }

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new AuditException('Could not read audit CSV output.');
                }
                if ($chunk === '') {
                    break;
                }
                $this->writeAll($output, $chunk);
            }
            if (!fflush($output)) {
                throw new AuditException('Could not flush audit CSV output.');
            }
        } finally {
            fclose($output);
        }
    }

    /** @param resource $output */
    private function writeAll(mixed $output, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new AuditException('Could not write audit CSV output.');
            }
            $offset += $written;
        }
    }

    private function openStagingStream(): mixed
    {
        $stream = fopen(self::STAGING_URI, 'w+b');
        if ($stream === false) {
            return false;
        }

        $meta     = stream_get_meta_data($stream);
        $mode     = (string) ($meta['mode'] ?? '');
        $uri      = (string) ($meta['uri'] ?? '');
        $writable = strpbrk($mode, 'waxc+') !== false;
        if (empty($meta['seekable']) || !$writable || !str_contains($uri, 'php://temp')) {
            fclose($stream);

            return false;
        }

        return $stream;
    }

    private function downloadFilename(AuditRun $run): string
    {
        $date = $this->calendarDate($run->finishedAt)
            ?? $this->calendarDate($run->startedAt)
            ?? gmdate('Y-m-d');
        $name = 'contentlatch-audit-' . $run->id . '-' . $date . '.csv';
        if (preg_match('/\Acontentlatch-audit-[1-9]\d*-\d{4}-\d{2}-\d{2}\.csv\z/', $name) !== 1) {
            return 'contentlatch-audit-export.csv';
        }

        return $name;
    }

    private function calendarDate(?string $value): ?string
    {
        if ($value === null || preg_match('/\A(\d{4}-\d{2}-\d{2})(?: \d{2}:\d{2}:\d{2})?\z/', trim($value), $matches) !== 1) {
            return null;
        }

        $date   = $matches[1];
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || (is_array($errors) && ((($errors['warning_count'] ?? 0) > 0) || (($errors['error_count'] ?? 0) > 0)))
            || $parsed->format('Y-m-d') !== $date
        ) {
            return null;
        }

        return $date;
    }

    /**
     * Drop markup buffered before this callback so it cannot prefix the file.
     * A buffer that cannot be removed stops the download before any CSV header.
     */
    private function discardBufferedOutput(): void
    {
        $floor = 0;
        $outputBufferFloor = $this->outputBufferFloor;
        if (is_callable($outputBufferFloor)) {
            $floor = max(0, (int) $outputBufferFloor());
        }

        while (ob_get_level() > $floor) {
            if (!ob_end_clean()) {
                throw new AuditException('Could not prepare audit CSV output.');
            }
        }
    }

    private function headersAlreadySent(): bool
    {
        return headers_sent();
    }

    private function reject(string $message, int $status): void
    {
        wp_die(
            esc_html($message),
            esc_html__('ContentLatch', 'contentlatch'),
            array('response' => $status)
        );
    }

    /**
     * Stop the admin request after a download so later output cannot be appended.
     */
    private function endResponse(): void
    {
        $finish = $this->finishResponse;
        if (is_callable($finish)) {
            $finish();

            return;
        }

        exit;
    }

    /** @param resource|null $stream */
    private function closeStream(mixed &$stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
        $stream = null;
    }

    private function log(Throwable $exception): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('ContentLatch audit export failed: ' . $exception->getMessage());
        }
    }
}
