<?php
/**
 * Shows persisted Audit blocking findings when arriving from Edit content.
 *
 * Informational only. Save/publish validation is unchanged.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\AuditPresentation;
use ContentGuard\Application\EditorAuditIssues;
use ContentGuard\Application\EditorFieldNavigation;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Infrastructure\WordPress\Capabilities;

final class EditorAuditNotice
{
    public function __construct(
        private ContentAuditService $audit,
        private RuleRepositoryInterface $rules,
    ) {
    }

    public static function register(ContentAuditService $audit, RuleRepositoryInterface $rules): void
    {
        $page = new self($audit, $rules);
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
        add_action('admin_notices', array($page, 'onAdminNotices'));
    }

    public static function shouldEnqueue(string $hook): bool
    {
        return $hook === 'post.php' || $hook === 'post-new.php';
    }

    /**
     * @param array<string, mixed> $request
     */
    public function issuesForRequest(int $postId, array $request): array
    {
        $runId = EditorFieldNavigation::requestedRunId($request);
        if ($runId <= 0 || $postId <= 0) {
            return array();
        }

        $canManage = Capabilities::currentUserCanManage();
        $canEdit   = function_exists('current_user_can') && current_user_can('edit_post', $postId);

        if (!EditorAuditIssues::canAccess($postId, $canManage, $canEdit)) {
            return array();
        }

        $findings = $this->audit->blockingFindingsForPost($runId, $postId);

        return EditorAuditIssues::fromFindings($findings, $postId, $this->fieldLabels($findings));
    }

    public function enqueue(string $hook): void
    {
        if (!self::shouldEnqueue($hook)) {
            return;
        }

        $postId = $this->editorPostId();
        $issues = $this->issuesForRequest($postId, $_GET);
        if ($issues === array()) {
            return;
        }

        $screen  = function_exists('get_current_screen') ? get_current_screen() : null;
        $isBlock = is_object($screen) && !empty($screen->is_block_editor);
        if (!$isBlock || !function_exists('wp_register_script')) {
            return;
        }

        EditorFieldFocus::enqueueAssets($_GET);

        wp_register_script(
            'contentguard-editor-audit',
            CONTENTGUARD_URL . 'admin/js/editor-audit.js',
            array('wp-data', 'contentguard-editor-field'),
            \ContentGuard\Plugin::VERSION,
            true
        );
        wp_localize_script(
            'contentguard-editor-audit',
            'contentguardEditorAudit',
            array(
                'html' => EditorAuditIssues::noticeHtml($issues),
                'text' => EditorAuditIssues::noticeText($issues),
            )
        );
        wp_enqueue_script('contentguard-editor-audit');
    }

    public function onAdminNotices(): void
    {
        if ($this->isBlockEditorScreen()) {
            return;
        }

        $postId = $this->editorPostId();
        $issues = $this->issuesForRequest($postId, $_GET);
        $html   = EditorAuditIssues::classicNoticeHtml($issues);
        if ($html === '') {
            return;
        }

        echo function_exists('wp_kses_post') ? wp_kses_post($html) : $html;
    }

    /**
     * @param AuditFinding[] $findings
     * @return array<string, string>
     */
    private function fieldLabels(array $findings): array
    {
        $labels = array();
        $rules  = array();

        foreach ($findings as $finding) {
            if (!$finding instanceof AuditFinding) {
                continue;
            }

            $key = (string) $finding->ruleId . ':' . $finding->fieldKey;
            if (isset($labels[$key])) {
                continue;
            }

            $ruleId = (string) $finding->ruleId;
            if (!array_key_exists($ruleId, $rules)) {
                $rules[$ruleId] = $this->rules->find($finding->ruleId);
            }

            $labels[$key] = AuditPresentation::fieldLabel($rules[$ruleId], $finding->fieldKey);
        }

        return $labels;
    }

    private function editorPostId(): int
    {
        if (isset($_GET['post']) && is_numeric($_GET['post'])) {
            return (int) $_GET['post'];
        }

        if (isset($GLOBALS['post']) && is_object($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            return (int) $GLOBALS['post']->ID;
        }

        return 0;
    }

    private function isBlockEditorScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return is_object($screen) && !empty($screen->is_block_editor);
    }
}
