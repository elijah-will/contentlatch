<?php
/**
 * ContentGuard Rules admin list and editor.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

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
            array(),
            \ContentGuard\Plugin::VERSION,
            true
        );
        wp_localize_script(
            'contentguard-rules',
            'contentguardRules',
            array(
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce(RuleCommandService::NONCE_ACTION),
                'fieldsAction' => RulesController::ACTION_FIELDS,
                'operators'  => $this->operatorLabels('text'),
                'operatorsByType' => array(
                    'default' => $this->operatorLabels('text'),
                    'number'  => $this->operatorLabels('number'),
                    'range'   => $this->operatorLabels('range'),
                ),
                'validators' => array(
                    'required'       => __('is required', 'contentguard'),
                    'min_length'     => __('Minimum length', 'contentguard'),
                    'max_length'     => __('Maximum length', 'contentguard'),
                    'allowed_values' => __('Allowed values', 'contentguard'),
                ),
                'preview'    => array(
                    'needThen'        => RulePreview::needThenMessage(),
                    'incompleteWhen'  => RulePreview::incompleteWhenMessage(),
                    'incompleteThen'  => RulePreview::incompleteThenMessage(),
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
        if (isset($_GET['action']) && (string) $_GET['action'] === 'new') {
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
     * Active before inactive; Blocking before Warning within each status.
     * Relative order inside a status+severity group is kept.
     *
     * @param Rule[] $rules
     * @return Rule[]
     */
    public static function sortForList(array $rules): array
    {
        $buckets = array(
            'active-fail'       => array(),
            'active-warning'    => array(),
            'inactive-fail'     => array(),
            'inactive-warning'  => array(),
        );

        foreach ($rules as $rule) {
            if (!$rule instanceof Rule) {
                continue;
            }

            $status   = $rule->status === RuleStatus::Active ? 'active' : 'inactive';
            $severity = $rule->severity === RuleSeverity::Warning ? 'warning' : 'fail';
            $buckets[$status . '-' . $severity][] = $rule;
        }

        return array_values(array_merge(
            $buckets['active-fail'],
            $buckets['active-warning'],
            $buckets['inactive-fail'],
            $buckets['inactive-warning']
        ));
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
        return 'None of these rules are active. Inactive rules are not currently being enforced. Activate a rule to allow ContentGuard to validate content.';
    }

    public static function ruleImpactLabel(?AuditRun $latestComplete, Rule $rule, ?AuditRuleImpact $impact): string
    {
        if ($latestComplete === null) {
            return 'No completed audit yet';
        }

        $postCount = $impact?->postCount ?? 0;
        if ($postCount === 0) {
            return 'No findings in latest audit';
        }

        if ($rule->severity === RuleSeverity::Warning) {
            return $postCount === 1
                ? '1 content item with warnings'
                : $postCount . ' content items with warnings';
        }

        return $postCount === 1
            ? '1 content item failing'
            : $postCount . ' content items failing';
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
        return AdminNotice::fromQuery($_GET);
    }

    /**
     * @return array<string, string>
     */
    private function operatorLabels(string $fieldType): array
    {
        $labels = array();
        foreach (ConditionOperators::labelsForFieldType($fieldType) as $id => $label) {
            $labels[$id] = __($label, 'contentguard');
        }

        return $labels;
    }
}
