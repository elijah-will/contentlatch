<?php
/**
 * Capability- and nonce-gated audit AJAX actions.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Admin;

defined('ABSPATH') || exit;

use ContentLatch\Application\Audit\ContentAuditService;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Infrastructure\WordPress\Capabilities;

final class AuditAjaxController
{
    public const ACTION_START  = 'contentlatch_audit_start';
    public const ACTION_BATCH  = 'contentlatch_audit_batch';
    public const ACTION_CANCEL = 'contentlatch_audit_cancel';
    public const ACTION_STATUS = 'contentlatch_audit_status';

    /**
     * @param callable(): bool       $canManage
     * @param callable(string): bool $verifyNonce Used by dispatch() unit tests only.
     *                                           WordPress AJAX entry points call check_ajax_referer().
     */
    /**
     * @var array<string, mixed>|null
     */
    private ?array $dispatched = null;

    public function __construct(
        private ContentAuditService $audit,
        private mixed $canManage,
        private mixed $verifyNonce,
    ) {
    }

    public static function register(ContentAuditService $audit): void
    {
        $controller = new self(
            $audit,
            array(Capabilities::class, 'currentUserCanManage'),
            static function (string $nonce): bool {
                return (bool) wp_verify_nonce($nonce, ContentAuditService::NONCE_ACTION);
            }
        );

        add_action('wp_ajax_' . self::ACTION_START, array($controller, 'start'));
        add_action('wp_ajax_' . self::ACTION_BATCH, array($controller, 'batch'));
        add_action('wp_ajax_' . self::ACTION_CANCEL, array($controller, 'cancel'));
        add_action('wp_ajax_' . self::ACTION_STATUS, array($controller, 'status'));
    }

    public function start(): void
    {
        $this->respond(function (): array {
            $run = $this->audit->start($this->currentUserId());

            return array('run' => $this->runPayload($run));
        });
    }

    public function batch(): void
    {
        $this->respond(function (): array {
            $run = $this->audit->processBatch($this->runId());

            return array('run' => $this->runPayload($run));
        });
    }

    public function cancel(): void
    {
        $this->respond(function (): array {
            $run = $this->audit->cancel($this->runId());

            return array('run' => $this->runPayload($run));
        });
    }

    public function status(): void
    {
        $this->respond(function (): array {
            $runId = $this->postedInt('run_id');
            $run   = $runId > 0 ? $this->audit->getRun($runId) : $this->audit->getActiveRun();

            return array(
                'run'             => $run !== null ? $this->runPayload($run) : null,
                'latest_complete' => $this->audit->getLatestCompleteRun() !== null
                    ? $this->runPayload($this->audit->getLatestCompleteRun())
                    : null,
            );
        });
    }

    /**
     * @param callable(): array<string, mixed> $handler
     */
    public function dispatch(string $action, array $request): array
    {
        $previous         = $this->dispatched;
        $this->dispatched = $request;

        try {
            return $this->dispatchAction($action);
        } finally {
            $this->dispatched = $previous;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchAction(string $action): array
    {
        $denied = $this->authorizeDispatched();
        if ($denied !== null) {
            return $denied;
        }

        return match ($action) {
            self::ACTION_START  => $this->handleDispatched(fn () => array('run' => $this->runPayload($this->audit->start($this->currentUserId())))),
            self::ACTION_BATCH  => $this->handleDispatched(fn () => array('run' => $this->runPayload($this->audit->processBatch($this->runId())))),
            self::ACTION_CANCEL => $this->handleDispatched(fn () => array('run' => $this->runPayload($this->audit->cancel($this->runId())))),
            self::ACTION_STATUS => $this->handleDispatched(function (): array {
                $runId = $this->postedInt('run_id');
                $run   = $runId > 0 ? $this->audit->getRun($runId) : $this->audit->getActiveRun();

                return array(
                    'run'             => $run !== null ? $this->runPayload($run) : null,
                    'latest_complete' => $this->audit->getLatestCompleteRun() !== null
                        ? $this->runPayload($this->audit->getLatestCompleteRun())
                        : null,
                );
            }),
            default => array('ok' => false, 'message' => __('Unknown audit action.', 'contentlatch')),
        };
    }

    /**
     * WordPress wp_ajax_* entry: capability + check_ajax_referer before the handler runs.
     *
     * @param callable(): array<string, mixed> $handler
     */
    private function respond(callable $handler): void
    {
        $denied = $this->authorizeWordPressAjax();
        if ($denied !== null) {
            $this->sendJson($denied);

            return;
        }

        try {
            $this->sendJson(array_merge(array('ok' => true), $handler()));
        } catch (AuditException $exception) {
            $this->sendJson(array('ok' => false, 'message' => $exception->getMessage()));
        } finally {
            // Clear the verified request bag captured by authorizeWordPressAjax().
            $this->dispatched = null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJson(array $payload): void
    {
        if (!function_exists('wp_send_json')) {
            return;
        }

        if (!($payload['ok'] ?? false)) {
            wp_send_json($payload, 400);

            return;
        }

        wp_send_json($payload);
    }

    /**
     * @param callable(): array<string, mixed> $handler
     * @return array<string, mixed>
     */
    private function handleDispatched(callable $handler): array
    {
        try {
            return array_merge(array('ok' => true), $handler());
        } catch (AuditException $exception) {
            return array('ok' => false, 'message' => $exception->getMessage());
        }
    }

    private function runId(): int
    {
        return $this->postedInt('run_id');
    }

    private function currentUserId(): int
    {
        if (function_exists('get_current_user_id')) {
            return (int) get_current_user_id();
        }

        return $this->postedInt('user_id');
    }

    /**
     * Production AJAX authorization. Does not use injected nonce callbacks.
     *
     * After check_ajax_referer succeeds, copies needed $_POST fields into
     * $this->dispatched in this same method so handlers never read $_POST
     * outside a verified scope (Plugin Check / WPCS).
     *
     * @return array{ok: false, message: string}|null
     */
    private function authorizeWordPressAjax(): ?array
    {
        $canManage = $this->canManage;
        if (!is_callable($canManage) || !$canManage()) {
            return array('ok' => false, 'message' => __('You are not allowed to run ContentLatch audits.', 'contentlatch'));
        }

        if (check_ajax_referer(ContentAuditService::NONCE_ACTION, '_wpnonce', false) === false) {
            return array('ok' => false, 'message' => __('Invalid audit nonce.', 'contentlatch'));
        }

        $this->dispatched = array(
            '_wpnonce' => isset($_POST['_wpnonce'])
                ? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce']))
                : '',
            'run_id'   => isset($_POST['run_id'])
                ? absint(wp_unslash((string) $_POST['run_id']))
                : 0,
            'user_id'  => isset($_POST['user_id'])
                ? absint(wp_unslash((string) $_POST['user_id']))
                : 0,
        );

        return null;
    }

    /**
     * Unit-test dispatch() authorization via request array + optional verifyNonce double.
     *
     * @return array{ok: false, message: string}|null
     */
    private function authorizeDispatched(): ?array
    {
        $canManage = $this->canManage;
        if (!is_callable($canManage) || !$canManage()) {
            return array('ok' => false, 'message' => __('You are not allowed to run ContentLatch audits.', 'contentlatch'));
        }

        $nonce  = $this->postedNonce();
        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($nonce)) {
            return array('ok' => false, 'message' => __('Invalid audit nonce.', 'contentlatch'));
        }

        return null;
    }

    private function postedNonce(): string
    {
        return sanitize_text_field((string) ($this->dispatched['_wpnonce'] ?? ''));
    }

    private function postedInt(string $key): int
    {
        return absint($this->dispatched[$key] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(\ContentLatch\Application\Audit\AuditRun $run): array
    {
        return array(
            'id'                  => $run->id,
            'status'              => $run->status->value,
            'posts_scanned'       => $run->postsScanned,
            'posts_passed'        => $run->postsPassed,
            'posts_warned'        => $run->postsWarned,
            'posts_failed'        => $run->postsFailed,
            'posts_not_evaluated' => $run->postsNotEvaluated,
            'posts_total'         => $run->postsTotal,
            'cursor'              => $run->cursor,
            'progress'            => $run->progressPercent(),
            'post_types'          => $run->postTypes,
            'error_message'       => $run->errorMessage,
        );
    }
}
