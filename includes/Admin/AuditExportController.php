<?php
/**
 * Authenticated download of one completed Content Audit CSV.
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

    private bool $exportRunWasRequested = false;

    private int $exportRunId = 0;

    /**
     * @param callable(): bool              $canManage
     * @param callable(string, string): bool $verifyNonce
     * @param callable(): void|null         $finishResponse   Test seam. Production exits.
     * @param callable(): int|null          $outputBufferFloor Test seam. Production clears every buffer.
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
            static function (string $nonce, string $action): bool {
                return wp_verify_nonce($nonce, $action) !== false;
            }
        );

        add_action('admin_post_' . self::ACTION, array($controller, 'export'));
    }

    /**
     * Nonce action for one export request. A selected run is part of the action
     * so a nonce issued for one completed audit cannot be reused for another.
     */
    public static function nonceAction(int $runId = 0): string
    {
        if ($runId > 0) {
            return self::NONCE_ACTION . ':' . $runId;
        }

        return self::NONCE_ACTION;
    }

    public function export(): void
    {
        $this->downloadStarted       = false;
        $this->exportRunWasRequested = false;
        $this->exportRunId           = 0;
        $stream                      = null;

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
     * Authorize, pin the selected completed run, and finish the CSV before any
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

        $run = $this->selectedRun();
        if (!$run instanceof AuditRun) {
            return null;
        }

        // The id and filename stay pinned to this run. Dashboard filters are
        // never read. The exporter's count, not a separate COUNT query, decides
        // whether this run has findings.
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
            $message = $this->exportRunWasRequested
                ? __('The selected ContentLatch audit has no findings to export.', 'contentlatch')
                : __('The latest completed ContentLatch audit has no findings to export.', 'contentlatch');
            $this->reject($message, 400);

            return null;
        }

        return $filename;
    }

    /**
     * Capability is checked before any request field is read. The nonce action
     * includes a well-formed run id, so that id is read only to verify the nonce.
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

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- This reads the nonce itself; it is verified below through $this->verifyNonce (wp_verify_nonce() in production).
        $nonce = isset($_REQUEST['_wpnonce']) && is_scalar($_REQUEST['_wpnonce'])
            ? sanitize_text_field(wp_unslash((string) $_REQUEST['_wpnonce']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ($nonce === '') {
            return array(
                'message' => __('Missing audit export nonce.', 'contentlatch'),
                'status'  => 403,
            );
        }

        $requested = $this->requestedRunId();
        $action    = $requested['id'] > 0
            ? self::nonceAction($requested['id'])
            : self::NONCE_ACTION;
        $verify    = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($nonce, $action)) {
            return array(
                'message' => __('Invalid audit export nonce.', 'contentlatch'),
                'status'  => 403,
            );
        }

        if ($requested['invalid']) {
            return array(
                'message' => __('Invalid audit run.', 'contentlatch'),
                'status'  => 400,
            );
        }

        $this->exportRunWasRequested = $requested['present'];
        $this->exportRunId           = $requested['id'];

        return null;
    }

    /**
     * Load the requested completed run, or the latest completed run when no id
     * was supplied. Invalid and incomplete runs are rejected here and are not
     * replaced with another audit.
     */
    private function selectedRun(): ?AuditRun
    {
        if ($this->exportRunWasRequested) {
            try {
                $run = $this->audit->getRun($this->exportRunId);
            } catch (AuditException) {
                $this->reject(__('Audit run not found.', 'contentlatch'), 400);

                return null;
            }

            if ($run->status !== AuditRunStatus::Complete || $run->id !== $this->exportRunId) {
                $this->reject(__('Only completed audits can be exported.', 'contentlatch'), 400);

                return null;
            }

            return $run;
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

        return $run;
    }

    /**
     * @return array{present: bool, invalid: bool, id: int}
     */
    private function requestedRunId(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The run id selects the per-run nonce action, so it must be read before verification. It is accepted below only as a canonical positive integer; sanitize_text_field() would loosen that (" 2" becomes "2").
        $raw = isset($_REQUEST['run']) ? wp_unslash($_REQUEST['run']) : null;
        if ($raw === null) {
            return array(
                'present' => false,
                'invalid' => false,
                'id'      => 0,
            );
        }

        if (!is_scalar($raw)) {
            return array(
                'present' => true,
                'invalid' => true,
                'id'      => 0,
            );
        }

        $value = (string) $raw;
        $id    = ctype_digit($value) ? (int) $value : 0;
        if ($id < 1 || (string) $id !== $value) {
            return array(
                'present' => true,
                'invalid' => true,
                'id'      => 0,
            );
        }

        return array(
            'present' => true,
            'invalid' => false,
            'id'      => $id,
        );
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
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads the php://temp staging stream; WP_Filesystem has no stream API.
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
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the php://output handle opened above, not a file.
            fclose($output);
        }
    }

    /** @param resource $output */
    private function writeAll(mixed $output, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streams the CSV download to php://output; no file is written.
            $written = fwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new AuditException('Could not write audit CSV output.');
            }
            $offset += $written;
        }
    }

    private function openStagingStream(): mixed
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opens the php://temp staging stream; WP_Filesystem cannot open PHP stream wrappers.
        $stream = fopen(self::STAGING_URI, 'w+b');
        if ($stream === false) {
            return false;
        }

        $meta     = stream_get_meta_data($stream);
        $mode     = (string) ($meta['mode'] ?? '');
        $uri      = (string) ($meta['uri'] ?? '');
        $writable = strpbrk($mode, 'waxc+') !== false;
        if (empty($meta['seekable']) || !$writable || !str_contains($uri, 'php://temp')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the rejected php://temp staging stream, not a file.
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
            array('response' => (int) $status)
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
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the php://temp staging stream, not a file.
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
