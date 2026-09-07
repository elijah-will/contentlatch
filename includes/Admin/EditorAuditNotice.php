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
use WP_REST_Request;
use WP_REST_Response;

final class EditorAuditNotice
{
    public const REST_NAMESPACE = 'contentguard/v1';
    public const REST_ROUTE     = '/editor-blockers/(?P<id>\d+)';

    /**
     * @param callable(int $postId): string|null $postTypeOf
     * @param callable(): bool|null $canManage
     * @param callable(int $postId): bool|null $canEditPost
     */
    public function __construct(
        private ContentAuditService $audit,
        private RuleRepositoryInterface $rules,
        private mixed $postTypeOf = null,
        private mixed $canManage = null,
        private mixed $canEditPost = null,
    ) {
    }

    public static function register(ContentAuditService $audit, RuleRepositoryInterface $rules): void
    {
        $page = new self(
            $audit,
            $rules,
            static function (int $postId): string {
                if (!function_exists('get_post_type')) {
                    return '';
                }

                $type = get_post_type($postId);

                return is_string($type) ? $type : '';
            }
        );
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
        add_action('admin_notices', array($page, 'onAdminNotices'));
        add_action('rest_api_init', array($page, 'registerRestRoute'));
        add_filter('redirect_post_location', array($page, 'preserveAuditRunOnRedirect'));
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

        if (!EditorAuditIssues::canAccess($postId, $this->userCanManage(), $this->userCanEdit($postId))) {
            return array();
        }

        $findings = $this->audit->blockingFindingsForPost($runId, $postId);
        if ($findings === array()) {
            return array();
        }

        $persisted = EditorAuditIssues::fromFindings($findings, $postId, $this->fieldLabels($findings));
        $allowed   = array_values(array_filter(array_map(
            static fn (array $issue): string => (string) ($issue['fieldKey'] ?? ''),
            $persisted
        )));

        $live = $this->liveIssues($postId, $allowed);

        return $live !== null ? $live : $persisted;
    }

    /**
     * @param list<string> $allowedKeys
     * @return list<array{message: string, label: string, fieldKey: string}>|null
     */
    private function liveIssues(int $postId, array $allowedKeys): ?array
    {
        $postTypeOf = $this->postTypeOf;
        $postType   = is_callable($postTypeOf) ? (string) $postTypeOf($postId) : '';
        if ($postType === '') {
            return null;
        }

        $evaluation = $this->audit->evaluateStoredPost($postId, $postType);
        if ($evaluation === null) {
            return null;
        }

        return EditorAuditIssues::scopedToFieldKeys(
            EditorAuditIssues::fromEvaluation($evaluation, $postId),
            $allowedKeys
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array{issues: list<array{message: string, label: string, fieldKey: string}>, html: string, text: string}
     */
    public function payloadForRequest(int $postId, array $request): array
    {
        return EditorAuditIssues::payload($this->issuesForRequest($postId, $request));
    }

    public function registerRestRoute(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'restBlockers'),
                'permission_callback' => function (WP_REST_Request $request): bool {
                    $id = (int) $request['id'];

                    return $this->userCanEdit($id) && $this->userCanManage();
                },
                'args'                => array(
                    'id'  => array(
                        'required' => true,
                        'type'     => 'integer',
                    ),
                    'run' => array(
                        'required' => false,
                        'type'     => 'integer',
                    ),
                ),
            )
        );
    }

    public function restBlockers(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request['id'];
        $runId  = EditorFieldNavigation::sanitizeRunId($request->get_param('run'));

        return new WP_REST_Response(
            $this->payloadForRequest(
                $postId,
                array(EditorFieldNavigation::AUDIT_RUN_ARG => $runId)
            ),
            200
        );
    }

    public function preserveAuditRunOnRedirect(string $location): string
    {
        $runId = EditorFieldNavigation::requestedRunId(
            is_array($_REQUEST) ? $_REQUEST : array()
        );
        if ($runId <= 0 && isset($_SERVER['HTTP_REFERER'])) {
            $query = array();
            parse_str((string) (parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_QUERY) ?? ''), $query);
            $runId = EditorFieldNavigation::requestedRunId($query);
        }

        if ($runId <= 0) {
            return $location;
        }

        return EditorFieldNavigation::appendToEditUrl($location, '', $runId);
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
            array('wp-api-fetch', 'wp-data', 'contentguard-editor-field'),
            \ContentGuard\Plugin::VERSION,
            true
        );
        $runId = EditorFieldNavigation::requestedRunId($_GET);
        wp_localize_script(
            'contentguard-editor-audit',
            'contentguardEditorAudit',
            array(
                'html'     => EditorAuditIssues::noticeHtml($issues),
                'text'     => EditorAuditIssues::noticeText($issues),
                'restPath' => self::REST_NAMESPACE . '/editor-blockers/' . $postId
                    . ($runId > 0 ? '?run=' . $runId : ''),
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

    private function userCanManage(): bool
    {
        if (is_callable($this->canManage)) {
            return (bool) ($this->canManage)();
        }

        return Capabilities::currentUserCanManage();
    }

    private function userCanEdit(int $postId): bool
    {
        if (is_callable($this->canEditPost)) {
            return (bool) ($this->canEditPost)($postId);
        }

        return $postId > 0 && function_exists('current_user_can') && current_user_can('edit_post', $postId);
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
