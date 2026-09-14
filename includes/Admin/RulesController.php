<?php
/**
 * Capability- and nonce-gated rule builder mutations.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

use ContentGuard\Application\AdminNotice;
use ContentGuard\Application\Exception\ForbiddenRuleMutationException;
use ContentGuard\Application\Exception\RulePersistenceException;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleMutationPresentation;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Infrastructure\WordPress\Capabilities;
use ContentGuard\Infrastructure\WordPress\HttpRequest;
use ContentGuard\Infrastructure\WordPress\PostTypeRuleRepository;

final class RulesController
{
    public const ACTION_SAVE     = 'contentguard_save_rule';
    public const ACTION_DELETE   = 'contentguard_delete_rule';
    public const ACTION_STATUS   = 'contentguard_rule_status';
    public const ACTION_FIELDS   = 'contentguard_rule_fields';

    /**
     * @param callable(): bool       $canManage
     * @param callable(string): bool $verifyNonce
     */
    public function __construct(
        private RuleRepositoryInterface $rules,
        private RuleCommandService $commands,
        private RuleDocumentFactory $factory,
        private mixed $canManage,
        private mixed $verifyNonce,
        private ?RuleEditorDraftStore $drafts = null,
    ) {
    }

    public static function register(
        RuleRepositoryInterface $rules,
        RuleCommandService $commands,
        RuleDocumentFactory $factory,
        ?RuleEditorDraftStore $drafts = null,
    ): self {
        $controller = new self(
            $rules,
            $commands,
            $factory,
            array(Capabilities::class, 'currentUserCanManage'),
            static function (string $nonce): bool {
                return (bool) wp_verify_nonce($nonce, RuleCommandService::NONCE_ACTION);
            },
            $drafts
        );

        add_action('admin_post_' . self::ACTION_SAVE, array($controller, 'save'));
        add_action('admin_post_' . self::ACTION_DELETE, array($controller, 'delete'));
        add_action('admin_post_' . self::ACTION_STATUS, array($controller, 'status'));
        add_action('wp_ajax_' . self::ACTION_FIELDS, array($controller, 'fields'));

        return $controller;
    }

    public function save(): void
    {
        $request = HttpRequest::unslash(is_array($_POST) ? $_POST : array());
        $payload = $this->handleSave($request);
        $this->respondAdmin($payload, $this->redirectAfterSave($request, $payload));
    }

    public function delete(): void
    {
        $this->respondAdmin(
            $this->handleDelete(HttpRequest::unslash(is_array($_REQUEST) ? $_REQUEST : array())),
            admin_url('admin.php?page=' . RulesPage::SLUG)
        );
    }

    public function status(): void
    {
        $this->respondAdmin(
            $this->handleStatus(HttpRequest::unslash(is_array($_REQUEST) ? $_REQUEST : array())),
            admin_url('admin.php?page=' . RulesPage::SLUG)
        );
    }

    public function fields(): void
    {
        $payload = $this->handleFields(HttpRequest::unslash(is_array($_POST) ? $_POST : array()));
        if (!function_exists('wp_send_json')) {
            return;
        }

        if (!($payload['ok'] ?? false)) {
            wp_send_json($payload, 400);
        }

        wp_send_json($payload);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function dispatch(string $action, array $request): array
    {
        return match ($action) {
            self::ACTION_SAVE   => $this->handleSave($request),
            self::ACTION_DELETE => $this->handleDelete($request),
            self::ACTION_STATUS => $this->handleStatus($request),
            self::ACTION_FIELDS => $this->handleFields($request),
            default             => array('ok' => false, 'message' => 'Unknown rule action.'),
        };
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleSave(array $request): array
    {
        $gated = $this->gate($request);
        if ($gated !== null) {
            return $gated;
        }

        $id = self::submittedRuleId($request);
        $creating = $id <= 0;

        try {
            $rule = $this->factory->fromAdminInput($request);
            $saved = $this->commands->save($rule, $this->nonce($request));
            $savedId = (int) $saved->id;
            $reloaded = $savedId > 0 ? $this->rules->find($savedId) : null;
            if ($reloaded === null) {
                $details = $this->rules instanceof PostTypeRuleRepository
                    ? $this->rules->describeRead($savedId)
                    : array('id' => $savedId, 'store_hit' => false, 'hydrate_ok' => false);
                RuleMutationPresentation::logDebug('rule save could not be reloaded', $details);
                $draft = $this->preserveDraft($request, $id, $creating);

                return array(
                    'ok'      => false,
                    'message' => RuleMutationPresentation::unreadAfterSaveMessage($creating),
                    'rule_id' => $id,
                    'draft'   => $draft,
                );
            }

            $this->forgetDrafts($id, $savedId);

            return array(
                'ok'      => true,
                'rule'    => $reloaded->toArray(),
                'rule_id' => $reloaded->id,
                'message' => $creating
                    ? RuleMutationPresentation::addedMessage()
                    : RuleMutationPresentation::savedMessage(),
            );
        } catch (InvalidRuleException | ForbiddenRuleMutationException | RulePersistenceException $exception) {
            RuleMutationPresentation::logFailure('rule save failed', $exception);
            $draft = $this->preserveDraft($request, $id, $creating);

            return array(
                'ok'      => false,
                'message' => RuleMutationPresentation::saveFailureMessage($exception, $creating),
                'rule_id' => $id,
                'draft'   => $draft,
            );
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleDelete(array $request): array
    {
        $gated = $this->gate($request);
        if ($gated !== null) {
            return $gated;
        }

        $id = isset($request['rule_id']) ? (int) $request['rule_id'] : 0;
        if ($id <= 0) {
            return array('ok' => false, 'message' => 'Invalid rule.');
        }

        try {
            $deleted = $this->commands->delete($id, $this->nonce($request));
        } catch (ForbiddenRuleMutationException $exception) {
            return array('ok' => false, 'message' => $exception->getMessage());
        }

        return $deleted
            ? array('ok' => true, 'message' => 'Rule deleted.')
            : array('ok' => false, 'message' => 'Rule not found.');
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleStatus(array $request): array
    {
        $gated = $this->gate($request);
        if ($gated !== null) {
            return $gated;
        }

        $id = isset($request['rule_id']) ? (int) $request['rule_id'] : 0;
        $existing = $id > 0 ? $this->rules->find($id) : null;
        if ($existing === null) {
            return array('ok' => false, 'message' => 'Rule not found.');
        }

        $status = (string) ($request['status'] ?? '');
        $enum   = RuleStatus::tryFrom($status);
        if ($enum === null) {
            return array('ok' => false, 'message' => 'Invalid rule status.');
        }

        $data           = $existing->toArray();
        $data['status'] = $enum->value;

        try {
            $saved = $this->commands->save(Rule::fromArray($data), $this->nonce($request));
        } catch (InvalidRuleException | ForbiddenRuleMutationException | RulePersistenceException $exception) {
            return array('ok' => false, 'message' => $exception->getMessage());
        }

        return array(
            'ok'      => true,
            'rule'    => $saved->toArray(),
            'message' => $enum === RuleStatus::Active ? 'Rule activated.' : 'Rule deactivated.',
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleFields(array $request): array
    {
        $gated = $this->gate($request);
        if ($gated !== null) {
            return $gated;
        }

        $postType = (string) ($request['post_type'] ?? '');

        try {
            $fields = $this->factory->fieldsForPostType($postType);
        } catch (InvalidRuleException $exception) {
            return array('ok' => false, 'message' => $exception->getMessage());
        }

        return array('ok' => true, 'fields' => $fields);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function gate(array $request): ?array
    {
        $canManage = $this->canManage;
        if (!is_callable($canManage) || !$canManage()) {
            return array('ok' => false, 'message' => 'You are not allowed to manage ContentGuard rules.');
        }

        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($this->nonce($request))) {
            return array('ok' => false, 'message' => 'Invalid rule management nonce.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function nonce(array $request): string
    {
        return isset($request['_wpnonce']) ? (string) $request['_wpnonce'] : '';
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $payload
     */
    private function redirectAfterSave(array $request, array $payload = array()): string
    {
        if (($payload['ok'] ?? false) && isset($payload['rule_id']) && (int) $payload['rule_id'] > 0) {
            return admin_url('admin.php?page=' . RulesPage::SLUG . '&rule=' . (int) $payload['rule_id']);
        }

        $id = self::submittedRuleId($request);
        if ($id <= 0) {
            $id = (int) ($payload['rule_id'] ?? 0);
        }

        if ($id > 0 && $this->rules->find($id) !== null) {
            return admin_url('admin.php?page=' . RulesPage::SLUG . '&rule=' . $id);
        }

        return admin_url('admin.php?page=' . RulesPage::SLUG . '&action=new');
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function submittedRuleId(array $request): int
    {
        $raw = $request['rule_id'] ?? $request['id'] ?? '';

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : 0;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function preserveDraft(array $request, int $id, bool $creating): array
    {
        $draft = RuleEditorState::snapshot($request);
        if ($creating || ($id > 0 && $this->rules->find($id) === null)) {
            $draft = RuleMutationPresentation::draftForNewRule($draft) ?? $draft;
        }
        $this->drafts?->put($id, $draft);
        $this->drafts?->put(0, $draft);

        return $draft;
    }

    private function forgetDrafts(int $submittedId, int $savedId): void
    {
        $this->drafts?->forget($submittedId);
        $this->drafts?->forget(0);
        if ($savedId > 0) {
            $this->drafts?->forget($savedId);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respondAdmin(array $payload, string $fallback): void
    {
        $url = $fallback;
        if (($payload['ok'] ?? false) && isset($payload['rule_id'])) {
            $url = admin_url('admin.php?page=' . RulesPage::SLUG . '&rule=' . (int) $payload['rule_id']);
        }

        $ok  = (bool) ($payload['ok'] ?? false);
        $url = add_query_arg(
            AdminNotice::queryArgs($ok, (string) ($payload['message'] ?? '')),
            $url
        );
        $url = AdminNotice::appendTarget($url, $ok);

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
            exit;
        }
    }
}
