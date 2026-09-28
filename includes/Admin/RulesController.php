<?php
/**
 * Capability- and nonce-gated rule builder mutations.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Admin;

defined('ABSPATH') || exit;

use ContentLatch\Application\AdminNotice;
use ContentLatch\Application\Exception\ForbiddenRuleMutationException;
use ContentLatch\Application\Exception\RulePersistenceException;
use ContentLatch\Application\RuleCommandService;
use ContentLatch\Application\RuleDocumentFactory;
use ContentLatch\Application\RuleMutationPresentation;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Domain\Exception\InvalidRuleException;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\RuleStatus;
use ContentLatch\Infrastructure\WordPress\Capabilities;
use ContentLatch\Infrastructure\WordPress\PostTypeRuleRepository;

final class RulesController
{
    public const ACTION_SAVE     = 'contentlatch_save_rule';
    public const ACTION_DELETE   = 'contentlatch_delete_rule';
    public const ACTION_STATUS   = 'contentlatch_rule_status';
    public const ACTION_FIELDS   = 'contentlatch_rule_fields';

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
        $fallback = admin_url('admin.php?page=' . RulesPage::SLUG . '&action=new');
        if (!$this->callerCanManage()) {
            $this->respondAdmin(
                array('ok' => false, 'message' => __('You are not allowed to manage ContentLatch rules.', 'contentlatch')),
                $fallback
            );

            return;
        }

        $nonce = isset($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce']))
            : '';
        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($nonce)) {
            $this->respondAdmin(
                array('ok' => false, 'message' => __('Invalid rule management nonce.', 'contentlatch')),
                $fallback
            );

            return;
        }

        $request = $this->postedSaveRequest($nonce);
        $payload = $this->handleSave($request);
        $this->respondAdmin($payload, $this->redirectAfterSave($request, $payload));
    }

    public function delete(): void
    {
        $fallback = admin_url('admin.php?page=' . RulesPage::SLUG);
        if (!$this->callerCanManage()) {
            $this->respondAdmin(
                array('ok' => false, 'message' => __('You are not allowed to manage ContentLatch rules.', 'contentlatch')),
                $fallback
            );

            return;
        }

        $nonce = isset($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce']))
            : '';
        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($nonce)) {
            $this->respondAdmin(
                array('ok' => false, 'message' => __('Invalid rule management nonce.', 'contentlatch')),
                $fallback
            );

            return;
        }

        $this->respondAdmin(
            $this->handleDelete(array(
                '_wpnonce' => $nonce,
                'rule_id'  => isset($_GET['rule_id']) ? absint(wp_unslash((string) $_GET['rule_id'])) : 0,
            )),
            $fallback
        );
    }

    public function status(): void
    {
        $fallback = admin_url('admin.php?page=' . RulesPage::SLUG);
        if (!$this->callerCanManage()) {
            $this->respondAdmin(
                array('ok' => false, 'message' => __('You are not allowed to manage ContentLatch rules.', 'contentlatch')),
                $fallback
            );

            return;
        }

        $nonce = isset($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce']))
            : '';
        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($nonce)) {
            $this->respondAdmin(
                array('ok' => false, 'message' => __('Invalid rule management nonce.', 'contentlatch')),
                $fallback
            );

            return;
        }

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash((string) $_GET['status'])) : '';
        $this->respondAdmin(
            $this->handleStatus(array(
                '_wpnonce' => $nonce,
                'rule_id'  => isset($_GET['rule_id']) ? absint(wp_unslash((string) $_GET['rule_id'])) : 0,
                'status'   => $status,
            )),
            $fallback
        );
    }

    public function fields(): void
    {
        if (!$this->callerCanManage()) {
            $this->sendFields(array(
                'ok'      => false,
                'message' => __('You are not allowed to manage ContentLatch rules.', 'contentlatch'),
            ));

            return;
        }

        $nonce = isset($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce']))
            : '';
        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($nonce)) {
            $this->sendFields(array(
                'ok'      => false,
                'message' => __('Invalid rule management nonce.', 'contentlatch'),
            ));

            return;
        }

        $this->sendFields($this->handleFields(array(
            '_wpnonce'  => $nonce,
            'post_type' => isset($_POST['post_type'])
                ? sanitize_key(wp_unslash((string) $_POST['post_type']))
                : '',
        )));
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
            default             => array('ok' => false, 'message' => __('Unknown rule action.', 'contentlatch')),
        };
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleSave(array $request): array
    {
        $request = self::cleanSaveInput($request);
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
        $request = array(
            '_wpnonce' => self::singleLine($request['_wpnonce'] ?? ''),
            'rule_id'  => absint($request['rule_id'] ?? 0),
        );
        $gated = $this->gate($request);
        if ($gated !== null) {
            return $gated;
        }

        $id = isset($request['rule_id']) ? (int) $request['rule_id'] : 0;
        if ($id <= 0) {
            return array('ok' => false, 'message' => __('Invalid rule.', 'contentlatch'));
        }

        try {
            $deleted = $this->commands->delete($id, $this->nonce($request));
        } catch (ForbiddenRuleMutationException $exception) {
            return array('ok' => false, 'message' => $exception->getMessage());
        }

        return $deleted
            ? array('ok' => true, 'message' => __('Rule deleted.', 'contentlatch'))
            : array('ok' => false, 'message' => __('Rule not found.', 'contentlatch'));
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleStatus(array $request): array
    {
        $request = array(
            '_wpnonce' => self::singleLine($request['_wpnonce'] ?? ''),
            'rule_id'  => absint($request['rule_id'] ?? 0),
            'status'   => sanitize_key((string) ($request['status'] ?? '')),
        );
        $gated = $this->gate($request);
        if ($gated !== null) {
            return $gated;
        }

        $id = isset($request['rule_id']) ? (int) $request['rule_id'] : 0;
        $existing = $id > 0 ? $this->rules->find($id) : null;
        if ($existing === null) {
            return array('ok' => false, 'message' => __('Rule not found.', 'contentlatch'));
        }

        $status = (string) ($request['status'] ?? '');
        $enum   = RuleStatus::tryFrom($status);
        if ($enum === null) {
            return array('ok' => false, 'message' => __('Invalid rule status.', 'contentlatch'));
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
            'message' => $enum === RuleStatus::Active
                ? __('Rule activated.', 'contentlatch')
                : __('Rule deactivated.', 'contentlatch'),
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleFields(array $request): array
    {
        $request = array(
            '_wpnonce'  => self::singleLine($request['_wpnonce'] ?? ''),
            'post_type' => sanitize_key((string) ($request['post_type'] ?? '')),
        );
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
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function cleanSaveInput(array $input): array
    {
        $clean = array(
            '_wpnonce' => self::singleLine($input['_wpnonce'] ?? ''),
            'name'     => self::singleLine($input['name'] ?? ''),
            'message'  => self::singleLine($input['message'] ?? ''),
        );

        if (array_key_exists('rule_id', $input) || array_key_exists('id', $input)) {
            $clean['rule_id'] = absint($input['rule_id'] ?? $input['id'] ?? 0);
        }

        if (array_key_exists('target_post_type', $input) || array_key_exists('post_type', $input)) {
            $clean['target_post_type'] = sanitize_key((string) ($input['target_post_type'] ?? $input['post_type'] ?? ''));
        }

        if (array_key_exists('status', $input)) {
            $clean['status'] = sanitize_key((string) $input['status']);
        }

        if (array_key_exists('severity', $input)) {
            $clean['severity'] = sanitize_key((string) $input['severity']);
        }

        if (array_key_exists('conditions', $input)) {
            $clean['conditions'] = self::cleanRows($input['conditions'], array(
                'id'        => 'key',
                'field_key' => 'text',
                'operator'  => 'key',
                'operand'   => 'text',
            ));
        }

        if (array_key_exists('validations', $input)) {
            $clean['validations'] = self::cleanRows($input['validations'], array(
                'id'        => 'key',
                'field_key' => 'text',
                'type'      => 'key',
                'min'       => 'text',
                'max'       => 'text',
                'values'    => 'text',
                'message'   => 'text',
            ));
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendFields(array $payload): void
    {
        if (!function_exists('wp_send_json')) {
            return;
        }

        if (!($payload['ok'] ?? false)) {
            wp_send_json($payload, 400);
        }

        wp_send_json($payload);
    }

    private function callerCanManage(): bool
    {
        $canManage = $this->canManage;

        return is_callable($canManage) && (bool) $canManage();
    }

    /**
     * @return array<string, mixed>
     */
    private function postedSaveRequest(string $nonce): array
    {
        $input = array(
            '_wpnonce' => $nonce,
            'name'     => isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '',
            'message'  => isset($_POST['message']) ? sanitize_text_field(wp_unslash((string) $_POST['message'])) : '',
        );

        if (isset($_POST['rule_id'])) {
            $input['rule_id'] = absint(wp_unslash((string) $_POST['rule_id']));
        }
        if (isset($_POST['target_post_type'])) {
            $input['target_post_type'] = sanitize_key(wp_unslash((string) $_POST['target_post_type']));
        }
        if (isset($_POST['status'])) {
            $input['status'] = sanitize_key(wp_unslash((string) $_POST['status']));
        }
        if (isset($_POST['severity'])) {
            $input['severity'] = sanitize_key(wp_unslash((string) $_POST['severity']));
        }
        if (isset($_POST['conditions'])) {
            $conditions = wp_unslash($_POST['conditions']);
            $input['conditions'] = self::cleanRows(is_array($conditions) ? $conditions : array(), array(
                'id'        => 'key',
                'field_key' => 'text',
                'operator'  => 'key',
                'operand'   => 'text',
            ));
        }
        if (isset($_POST['validations'])) {
            $validations = wp_unslash($_POST['validations']);
            $input['validations'] = self::cleanRows(is_array($validations) ? $validations : array(), array(
                'id'        => 'key',
                'field_key' => 'text',
                'type'      => 'key',
                'min'       => 'text',
                'max'       => 'text',
                'values'    => 'text',
                'message'   => 'text',
            ));
        }

        return self::cleanSaveInput($input);
    }

    /**
     * @param array<string, 'text'|'key'> $fields
     * @return list<array<string, string>>
     */
    private static function cleanRows(mixed $rows, array $fields): array
    {
        if (!is_array($rows)) {
            return array();
        }

        $clean = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = array();
            foreach ($fields as $field => $kind) {
                $value = $row[$field] ?? '';
                if (is_array($value)) {
                    $item[$field] = '';
                    continue;
                }

                $item[$field] = $kind === 'key'
                    ? sanitize_key((string) $value)
                    : self::singleLine($value);
            }
            $clean[] = $item;
        }

        return $clean;
    }

    private static function singleLine(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function gate(array $request): ?array
    {
        $canManage = $this->canManage;
        if (!is_callable($canManage) || !$canManage()) {
            return array('ok' => false, 'message' => __('You are not allowed to manage ContentLatch rules.', 'contentlatch'));
        }

        $verify = $this->verifyNonce;
        if (!is_callable($verify) || !$verify($this->nonce($request))) {
            return array('ok' => false, 'message' => __('Invalid rule management nonce.', 'contentlatch'));
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
