<?php
/**
 * ContentGuard Rules admin list and editor.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

defined('ABSPATH') || exit;

use ContentGuard\Application\Audit\AuditRuleImpact;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\AdminNotice;
use ContentGuard\Application\ConditionOperators;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleMutationPresentation;
use ContentGuard\Application\RulePresentation;
use ContentGuard\Application\RulePreview;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Infrastructure\WordPress\Capabilities;

final class RulesPage
{
    public const SLUG = 'contentguard';

    public function __construct(
        private RuleRepositoryInterface $rules,
        private RuleDocumentFactory $factory,
        private ?RuleEditorDraftStore $drafts = null,
        private ?ContentAuditService $audit = null,
    ) {
    }

    public static function register(
        RuleRepositoryInterface $rules,
        RuleDocumentFactory $factory,
        ?RuleEditorDraftStore $drafts = null,
        ?ContentAuditService $audit = null,
    ): self {
        $page = new self($rules, $factory, $drafts, $audit);
        add_action('admin_menu', array($page, 'addMenu'));
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));

        return $page;
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('ContentGuard Rules', 'contentguard'),
            __('ContentGuard', 'contentguard'),
            Capabilities::MANAGE,
            self::SLUG,
            array($this, 'render'),
            'dashicons-yes-alt',
            81
        );
        add_submenu_page(
            self::SLUG,
            __('Rules', 'contentguard'),
            __('Rules', 'contentguard'),
            Capabilities::MANAGE,
            self::SLUG,
            array($this, 'render')
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }

        AdminAssets::enqueueShared();

        wp_register_style(
            'contentguard-rules',
            CONTENTGUARD_URL . 'admin/css/rules.css',
            array(AdminAssets::STYLE),
            \ContentGuard\Plugin::VERSION
        );
        wp_enqueue_style('contentguard-rules');

        wp_register_script(
            'contentguard-rules',
            CONTENTGUARD_URL . 'admin/js/rules.js',
            array('wp-i18n'),
            \ContentGuard\Plugin::VERSION,
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('contentguard-rules', 'contentguard', CONTENTGUARD_DIR . 'languages');
        }
        wp_localize_script(
            'contentguard-rules',
            'contentguardRules',
            array(
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce(RuleCommandService::NONCE_ACTION),
                'fieldsAction'    => RulesController::ACTION_FIELDS,
                'catalogFields'   => $this->catalogFieldsForRequest(),
                'deleteConfirm'   => __(
                    'Delete this rule? Audit findings for this rule will be kept.',
                    'contentguard'
                ),
                'operators'       => $this->operatorLabels('text'),
                'operatorsByType' => $this->operatorsByType(),
                'validators'      => array(
                    'required'       => __('is required', 'contentguard'),
                    'min_length'     => __('Minimum length', 'contentguard'),
                    'max_length'     => __('Maximum length', 'contentguard'),
                    'allowed_values' => __('Allowed values', 'contentguard'),
                ),
                'preview'         => array(
                    'needThen'              => RulePreview::needThenMessage(),
                    'needWhenOrThen'        => RulePreview::needWhenOrThenMessage(),
                    'incompleteWhen'        => RulePreview::incompleteWhenMessage(),
                    'incompleteThen'        => RulePreview::incompleteThenMessage(),
                    'conditionOnlyBlocking' => RulePreview::conditionOnlyBlockingMessage(),
                    'conditionOnlyWarning'  => RulePreview::conditionOnlyWarningMessage(),
                ),
                'i18n'            => array(
                    'chooseField'         => __('Choose a field', 'contentguard'),
                    'field'               => __('Field', 'contentguard'),
                    'yes'                 => __('Yes', 'contentguard'),
                    'no'                  => __('No', 'contentguard'),
                    'remove'              => __('Remove', 'contentguard'),
                    'whenField'           => __('WHEN field', 'contentguard'),
                    'operator'            => __('Operator', 'contentguard'),
                    'value'               => __('Value', 'contentguard'),
                    'thenField'           => __('THEN field', 'contentguard'),
                    'requirement'         => __('Requirement', 'contentguard'),
                    'customMessage'       => __('Custom message (optional)', 'contentguard'),
                    'characters'          => __('characters', 'contentguard'),
                    'warning'             => __('Warning:', 'contentguard'),
                    /* translators: %d: Condition number. */
                    'removeCondition'     => __('Remove condition %d', 'contentguard'),
                    /* translators: %d: Requirement number. */
                    'removeRequirement'   => __('Remove requirement %d', 'contentguard'),
                    'emptyAndNotEmpty'    => __(
                        'This rule cannot be saved because a field cannot be both empty and not empty.',
                        'contentguard'
                    ),
                    'missingThen'         => __('Each requirement needs a field and a validator.', 'contentguard'),
                    'emptyAndRequired'    => __(
                        'This rule cannot be saved because a field cannot be required when the rule only applies when that same field is empty.',
                        'contentguard'
                    ),
                    'notEmptyAndRequired' => __(
                        'This rule cannot be saved because a field is already required to have a value by the WHEN condition.',
                        'contentguard'
                    ),
                    'equalsAndRequired'   => __(
                        'This rule cannot be saved because a field that must already have a specific value does not need to be required.',
                        'contentguard'
                    ),
                    'allowedValues'       => __('Enter at least one allowed value.', 'contentguard'),
                    'minGtMax'            => __('Minimum length cannot be greater than maximum length.', 'contentguard'),
                    'missingWhenOrThen'   => __('Add a WHEN condition or THEN requirement.', 'contentguard'),
                ),
            )
        );
        wp_enqueue_script('contentguard-rules');
    }

    public function render(): void
    {
        if (!Capabilities::currentUserCanManage()) {
            wp_die(esc_html__('You are not allowed to access this page.', 'contentguard'));
        }

        $ruleId = isset($_GET['rule']) ? (int) wp_unslash((string) $_GET['rule']) : 0;
        $action = '';
        if (isset($_GET['action'])) {
            $action = (string) wp_unslash((string) $_GET['action']);
            $action = function_exists('sanitize_key')
                ? sanitize_key($action)
                : strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $action));
        }
        if ($action === 'new') {
            $this->renderEditor(null, 0);

            return;
        }

        if ($ruleId > 0) {
            $rule = $this->rules->find($ruleId);
            if ($rule === null) {
                $this->renderMissingRule($ruleId);

                return;
            }

            $this->renderEditor($rule, $ruleId);

            return;
        }

        $this->renderList();
    }

    private function renderList(): void
    {
        $rules           = $this->rules->findAll();
        $summaries       = array();
        $typesByPostType = array();
        foreach ($rules as $rule) {
            if (!isset($typesByPostType[$rule->postType])) {
                $typesByPostType[$rule->postType] = $this->fieldTypesFor($rule->postType);
            }

            $summaries[(string) $rule->id] = array(
                'conditions'  => RulePresentation::conditionsSummary($rule, $typesByPostType[$rule->postType]),
                'validations' => RulePresentation::validationsSummary($rule),
            );
        }

        $audit          = $this->audit;
        $latestComplete = $audit?->getLatestCompleteRun();
        $impacts        = array();
        if ($audit !== null && $latestComplete !== null) {
            foreach ($audit->countFindingsByRule($latestComplete->id) as $impact) {
                $impacts[(string) $impact->ruleId] = $impact;
            }
        }

        $notice         = $this->notice();
        $postTypeLabels = $this->factory->allowedPostTypes();
        $allInactive    = self::allRulesInactive($rules);
        $rules          = self::sortForList($rules);
        $view           = CONTENTGUARD_DIR . 'admin/views/rules-list.php';
        require $view;
    }

    /**
     * Visual groups for the Rule List, in display order.
     * Empty groups are omitted. Relative order inside a group is kept.
     *
     * @param Rule[] $rules
     * @return list<array{id: string, title: string, open: bool, rules: list<Rule>}>
     */
    public static function groupsForList(array $rules): array
    {
        $buckets = array(
            'active-fail' => array(
                'id'    => 'active-blocking',
                'title' => __('Active Blocking', 'contentguard'),
                'open'  => true,
                'rules' => array(),
            ),
            'active-warning' => array(
                'id'    => 'active-warning',
                'title' => __('Active Warning', 'contentguard'),
                'open'  => true,
                'rules' => array(),
            ),
            'inactive-fail' => array(
                'id'    => 'inactive-blocking',
                'title' => __('Inactive Blocking', 'contentguard'),
                'open'  => false,
                'rules' => array(),
            ),
            'inactive-warning' => array(
                'id'    => 'inactive-warning',
                'title' => __('Inactive Warning', 'contentguard'),
                'open'  => false,
                'rules' => array(),
            ),
        );

        foreach ($rules as $rule) {
            if (!$rule instanceof Rule) {
                continue;
            }

            $status   = $rule->status === RuleStatus::Active ? 'active' : 'inactive';
            $severity = $rule->severity === RuleSeverity::Warning ? 'warning' : 'fail';
            $key      = $status . '-' . $severity;
            if (!isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['rules'][] = $rule;
        }

        $groups = array();
        foreach ($buckets as $group) {
            if ($group['rules'] === array()) {
                continue;
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Active before inactive; Blocking before Warning within each status.
     * Relative order inside a status+severity group is kept.
     *
     * @param Rule[] $rules
     * @return Rule[]
     */
    public static function sortForList(array $rules): array
    {
        $sorted = array();
        foreach (self::groupsForList($rules) as $group) {
            foreach ($group['rules'] as $rule) {
                $sorted[] = $rule;
            }
        }

        return $sorted;
    }

    /**
     * @param Rule[] $rules
     */
    public static function allRulesInactive(array $rules): bool
    {
        if ($rules === array()) {
            return false;
        }

        foreach ($rules as $rule) {
            if ($rule instanceof Rule && $rule->status === RuleStatus::Active) {
                return false;
            }
        }

        return true;
    }

    public static function allInactiveNotice(): string
    {
        return __(
            'None of these rules are active. Inactive rules are not currently being enforced. Activate a rule to allow ContentGuard to validate content.',
            'contentguard'
        );
    }

    public static function ruleImpactLabel(?AuditRun $latestComplete, Rule $rule, ?AuditRuleImpact $impact): string
    {
        if ($latestComplete === null) {
            return __('No completed audit yet', 'contentguard');
        }

        $postCount = $impact?->postCount ?? 0;
        if ($postCount === 0) {
            return __('No findings in latest audit', 'contentguard');
        }

        if ($rule->severity === RuleSeverity::Warning) {
            return sprintf(
                /* translators: %d: number of content items */
                _n(
                    '%d content item with warnings',
                    '%d content items with warnings',
                    $postCount,
                    'contentguard'
                ),
                $postCount
            );
        }

        return sprintf(
            /* translators: %d: number of content items */
            _n(
                '%d content item failing',
                '%d content items failing',
                $postCount,
                'contentguard'
            ),
            $postCount
        );
    }

    /**
     * @return array<string, string>
     */
    private function fieldTypesFor(string $postType): array
    {
        try {
            $types = array();
            foreach ($this->factory->fieldsForPostType($postType) as $field) {
                $key  = (string) ($field['key'] ?? '');
                $type = (string) ($field['type'] ?? '');
                if ($key !== '') {
                    $types[$key] = $type;
                }
            }

            return $types;
        } catch (InvalidRuleException) {
            return array();
        }
    }

    /**
     * Catalog fields for the current rule-editor request.
     * Empty on the rules list screen.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogFieldsForRequest(): array
    {
        if (!$this->isEditorRequest()) {
            return array();
        }

        $ruleId = $this->requestRuleId();
        $rule   = $ruleId > 0 ? $this->rules->find($ruleId) : null;
        $editor = RuleEditorState::hydrate($this->drafts?->get($ruleId), $rule);

        $postTypes     = $this->factory->selectablePostTypes();
        $typeForFields = $editor->postType !== ''
            ? $editor->postType
            : (string) (array_key_first($postTypes) ?? '');

        if ($typeForFields === '' || !isset($this->factory->allowedPostTypes()[$typeForFields])) {
            return array();
        }

        try {
            return array_values($this->factory->fieldsForPostType($typeForFields));
        } catch (InvalidRuleException) {
            return array();
        }
    }

    private function isEditorRequest(): bool
    {
        return $this->requestAction() === 'new' || $this->requestRuleId() > 0;
    }

    private function requestAction(): string
    {
        if (!isset($_GET['action'])) {
            return '';
        }

        $action = (string) wp_unslash((string) $_GET['action']);

        return function_exists('sanitize_key')
            ? sanitize_key($action)
            : strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $action));
    }

    private function requestRuleId(): int
    {
        if (!isset($_GET['rule'])) {
            return 0;
        }

        return (int) wp_unslash((string) $_GET['rule']);
    }

    /**
     * @param array{type: string, message: string}|null $noticeOverride
     */
    private function renderEditor(?Rule $rule, int $ruleId, ?array $noticeOverride = null): void
    {
        $editor    = RuleEditorState::hydrate($this->drafts?->get($ruleId), $rule);
        $postTypes = $this->factory->selectablePostTypes();
        if ($editor->postType !== '' && !isset($postTypes[$editor->postType])) {
            $labels = $this->factory->allowedPostTypes();
            $postTypes[$editor->postType] = $labels[$editor->postType] ?? $editor->postType;
        }

        $fields = array();
        $typeForFields = $editor->postType !== '' ? $editor->postType : (string) (array_key_first($postTypes) ?? '');
        if ($typeForFields !== '' && isset($this->factory->allowedPostTypes()[$typeForFields])) {
            try {
                $fields = $this->factory->fieldsForPostType($typeForFields);
            } catch (InvalidRuleException) {
                $fields = array();
            }
        }

        $notice = $noticeOverride ?? $this->notice();
        $view   = CONTENTGUARD_DIR . 'admin/views/rule-edit.php';
        require $view;
    }

    private function renderMissingRule(int $ruleId): void
    {
        $draft = RuleMutationPresentation::draftForNewRule(
            $this->drafts?->get($ruleId) ?? $this->drafts?->get(0)
        );
        if ($draft !== null) {
            $this->drafts?->put(0, $draft);
            $this->renderEditor(
                null,
                0,
                $this->notice() ?? array(
                    'type'    => 'error',
                    'message' => RuleMutationPresentation::missingRuleMessage(),
                )
            );

            return;
        }

        $notice = $this->notice() ?? array(
            'type'    => 'error',
            'message' => RuleMutationPresentation::missingRuleMessage(),
        );

        echo '<div class="wrap contentguard"><div class="notice notice-error"><p>'
            . esc_html($notice['message'])
            . '</p><p><a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
            . esc_html__('Back to rules', 'contentguard')
            . '</a></p></div></div>';
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function notice(): ?array
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- AdminNotice::fromQuery unslashes and sanitize_text_field's the notice keys it reads.
        return AdminNotice::fromQuery(is_array($_GET) ? $_GET : array());
    }

    /**
     * @return array<string, string>
     */
    private function operatorLabels(string $fieldType): array
    {
        return ConditionOperators::labelsForFieldType($fieldType);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function operatorsByType(): array
    {
        return ConditionOperators::labelsByFieldType();
    }
}
