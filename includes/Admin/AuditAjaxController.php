<?php
/**
 * Capability- and nonce-gated audit AJAX actions.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

defined('ABSPATH') || exit;

use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\Exception\AuditException;
use ContentGuard\Infrastructure\WordPress\Capabilities;
use ContentGuard\Infrastructure\WordPress\HttpRequest;

final class AuditAjaxController
{
    public const ACTION_START  = 'contentguard_audit_start';
    public const ACTION_BATCH  = 'contentguard_audit_batch';
    public const ACTION_CANCEL = 'contentguard_audit_cancel';
    public const ACTION_STATUS = 'contentguard_audit_status';

    /**
     * @param callable(): bool       $canManage
     * @param callable(string): bool $verifyNonce
     */
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
            $runId = isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
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
        $_POST = $request;

        return match ($action) {
            self::ACTION_START  => $this->handle(fn () => array('run' => $this->runPayload($this->audit->start($this->currentUserId())))),
            self::ACTION_BATCH  => $this->handle(fn () => array('run' => $this->runPayload($this->audit->processBatch($this->runId())))),
            self::ACTION_CANCEL => $this->handle(fn () => array('run' => $this->runPayload($this->audit->cancel($this->runId())))),
            self::ACTION_STATUS => $this->handle(function (): array {
                $runId = isset($request['run_id']) ? (int) $request['run_id'] : 0;
                $run   = $runId > 0 ? $this->audit->getRun($runId) : $this->audit->getActiveRun();

                return array(
                    'run'             => $run !== null ? $this->runPayload($run) : null,
                    'latest_complete' => $this->audit->getLatestCompleteRun() !== null
                        ? $this->runPayload($this->audit->getLatestCompleteRun())
                        : null,
                );
            }),
            default => array('ok' => false, 'message' => __('Unknown audit action.', 'contentguard')),
        };
    }

    /**
     * @param callable(): array<string, mixed> $handler
     */
    private function respond(callable $handler): void
    {
        $payload = $this->handle($handler);
        if (!function_exists('wp_send_json')) {
            return;
        }

        if (!($payload['ok'] ?? false)) {
            wp_send_json($payload, 400);
        }

        wp_send_json($payload);
    }

    /**
     * @param callable(): array<string, mixed> $handler
     * @return array<string, mixed>
     */
    private function handle(callable $handler): array
    {
        $canManage = $this->canManage;
        if (!is_callable($canManage) || !$canManage()) {
            return array('ok' => false, 'message' => __('You are not allowed to run ContentGuard audits.', 'contentguard'));
        }

        $_POST = HttpRequest::unslash(is_array($_POST) ? $_POST : array());
        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($nonce)) {
            return array('ok' => false, 'message' => __('Invalid audit nonce.', 'contentguard'));
        }

        try {
            return array_merge(array('ok' => true), $handler());
        } catch (AuditException $exception) {
            return array('ok' => false, 'message' => $exception->getMessage());
        }
    }

    private function runId(): int
    {
        return isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0;
    }

    private function currentUserId(): int
    {
        if (function_exists('get_current_user_id')) {
            return (int) get_current_user_id();
        }

        return isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(\ContentGuard\Application\Audit\AuditRun $run): array
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
