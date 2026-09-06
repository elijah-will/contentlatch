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
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RulePresentation;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleSeverity;
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

        wp_register_style(
            'contentguard-rules',
            CONTENTGUARD_URL . 'admin/css/rules.css',
            array(),
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
                'operators'  => array(
                    'equals'       => __('equals', 'contentguard'),
                    'not_equals'   => __('does not equal', 'contentguard'),
                    'is_empty'     => __('is empty', 'contentguard'),
                    'is_not_empty' => __('is not empty', 'contentguard'),
                ),
                'validators' => array(
                    'required'       => __('required', 'contentguard'),
                    'min_length'     => __('Minimum length (characters)', 'contentguard'),
                    'max_length'     => __('Maximum length (characters)', 'contentguard'),
                    'allowed_values' => __('allowed values', 'contentguard'),
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
                echo '<div class="wrap"><div class="notice notice-error"><p>'
                    . esc_html__('Rule not found.', 'contentguard')
                    . '</p></div></div>';

                return;
            }

            $this->renderEditor($rule, $ruleId);

            return;
        }

        $this->renderList();
    }

    private function renderList(): void
    {
        $rules      = $this->rules->findAll();
        $summaries  = array();
        foreach ($rules as $rule) {
            $summaries[(string) $rule->id] = array(
                'conditions'  => RulePresentation::conditionsSummary($rule),
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

        $notice = $this->notice();
        $view   = CONTENTGUARD_DIR . 'admin/views/rules-list.php';
        require $view;
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

    private function renderEditor(?Rule $rule, int $ruleId): void
    {
        $editor    = RuleEditorState::hydrate($this->drafts?->get($ruleId), $rule);
        $postTypes = $this->factory->allowedPostTypes();
        if ($editor->postType !== '' && !isset($postTypes[$editor->postType])) {
            $postTypes[$editor->postType] = $editor->postType;
        }

        $fields = array();
        $typeForFields = $editor->postType !== '' ? $editor->postType : '';
        if ($typeForFields !== '' && isset($this->factory->allowedPostTypes()[$typeForFields])) {
            $fields = $this->factory->fieldsForPostType($typeForFields);
        } elseif ($this->factory->allowedPostTypes() !== array()) {
            $first = array_key_first($this->factory->allowedPostTypes());
            if (is_string($first)) {
                $fields = $this->factory->fieldsForPostType($first);
            }
        }

        $notice = $this->notice();
        $view   = CONTENTGUARD_DIR . 'admin/views/rule-edit.php';
        require $view;
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function notice(): ?array
    {
        if (!isset($_GET['contentguard_notice'], $_GET['contentguard_msg'])) {
            return null;
        }

        $type = sanitize_key((string) wp_unslash((string) $_GET['contentguard_notice']));
        $message = sanitize_text_field((string) wp_unslash((string) rawurldecode((string) $_GET['contentguard_msg'])));

        if ($message === '' || !in_array($type, array('updated', 'error'), true)) {
            return null;
        }

        return array(
            'type'    => $type === 'updated' ? 'success' : 'error',
            'message' => $message,
        );
    }
}
