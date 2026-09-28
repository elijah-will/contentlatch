<?php
/**
 * Shows persisted Audit blocking findings when arriving from Edit content.
 *
 * Informational only. Save/publish validation is unchanged.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Admin;

defined('ABSPATH') || exit;

use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\ContentAuditService;
use ContentLatch\Application\AuditPresentation;
use ContentLatch\Application\EditorAuditIssues;
use ContentLatch\Application\EditorCoreNavigation;
use ContentLatch\Application\EditorNoticePresentation;
use ContentLatch\Application\EditorFieldNavigation;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Infrastructure\WordPress\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

final class EditorAuditNotice
{
    public const REST_NAMESPACE = 'contentlatch/v1';
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
     * @return list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>|null
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
     * @return array{issues: list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>, html: string, text: string}
     */
    public function payloadForRequest(int $postId, array $request): array
    {
        return EditorAuditIssues::payload(
            $this->issuesForRequest($postId, $request),
            EditorCoreNavigation::SURFACE_GUTENBERG
        );
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
        $runId = EditorFieldNavigation::requestedRunId($this->editorNavigationQuery());
        if ($runId <= 0 && isset($_SERVER['HTTP_REFERER'])) {
            $referer = esc_url_raw(wp_unslash((string) $_SERVER['HTTP_REFERER']));
            $query   = array();
            if ($referer !== '') {
                parse_str(self::refererQueryString($referer), $query);
            }
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
        $request = $this->editorNavigationQuery();
        $issues  = $this->issuesForRequest($postId, $request);
        if ($issues === array()) {
            return;
        }

        $screen  = function_exists('get_current_screen') ? get_current_screen() : null;
        $isBlock = is_object($screen) && !empty($screen->is_block_editor);
        if (!$isBlock || !function_exists('wp_register_script')) {
            return;
        }

        EditorFieldFocus::enqueueAssets($request, self::navigationExtras($issues, $request));

        wp_register_script(
            'contentlatch-editor-audit',
            CONTENTLATCH_URL . 'admin/js/editor-audit.js',
            array('wp-api-fetch', 'wp-data', 'contentlatch-editor-field'),
            \ContentLatch\Plugin::VERSION,
            true
        );
        $runId = EditorFieldNavigation::requestedRunId($request);
        wp_localize_script(
            'contentlatch-editor-audit',
            'contentlatchEditorAudit',
            array(
                'html'     => EditorAuditIssues::noticeHtml($issues, EditorCoreNavigation::SURFACE_GUTENBERG),
                'text'     => EditorAuditIssues::noticeText($issues),
                'restPath' => self::REST_NAMESPACE . '/editor-blockers/' . $postId
                    . ($runId > 0 ? '?run=' . $runId : ''),
            )
        );
        wp_enqueue_script('contentlatch-editor-audit');
    }

    public function onAdminNotices(): void
    {
        if ($this->isBlockEditorScreen()) {
            return;
        }

        $postId = $this->editorPostId();
        $request = $this->editorNavigationQuery();
        $issues  = $this->issuesForRequest($postId, $request);
        $html    = EditorAuditIssues::classicNoticeHtml($issues);
        if ($html === '') {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress branch uses wp_kses() with allowedNoticeHtml(); fallback is for non-WordPress/test environments. $html items are already escaped at construction.
        echo EditorNoticePresentation::kses($html);
    }

    /**
     * Navigation hints for the Audit field named in the editor URL.
     *
     * A single issue keeps its metadata. With several issues, the requested
     * contentlatch_field selects the matching issue when that match is unique
     * or every match names the same row. Differing rows are left unset.
     *
     * @param list<array{message?: string, label?: string, fieldKey?: string, layout?: string, affectedRows?: list<int>, repeaterPath?: list<array{repeater: string, display_row: int}>}> $issues
     * @param array<string, mixed> $request
     * @return array{layout?: string, displayRow?: int, repeaterPath?: list<array{repeater: string, display_row: int}>}
     */
    private static function navigationExtras(array $issues, array $request = array()): array
    {
        $issue = self::issueForNavigation($issues, $request);
        if ($issue === null) {
            return array();
        }

        return self::extrasFromIssue($issue);
    }

    /**
     * @param list<array{message?: string, label?: string, fieldKey?: string, layout?: string, affectedRows?: list<int>, repeaterPath?: list<array{repeater: string, display_row: int}>}> $issues
     * @param array<string, mixed> $request
     * @return array{message?: string, label?: string, fieldKey?: string, layout?: string, affectedRows?: list<int>, repeaterPath?: list<array{repeater: string, display_row: int}>}|null
     */
    private static function issueForNavigation(array $issues, array $request): ?array
    {
        if (count($issues) === 1) {
            return $issues[0];
        }

        $requested = EditorFieldNavigation::requestedFieldKey($request);
        if ($requested === '') {
            return null;
        }

        $matches = array();
        foreach ($issues as $issue) {
            $key = EditorFieldNavigation::navigationId((string) ($issue['fieldKey'] ?? ''));
            if ($key !== '' && $key === $requested) {
                $matches[] = $issue;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1 && self::navigationTargetsAgree($matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @param list<array{message?: string, label?: string, fieldKey?: string, layout?: string, affectedRows?: list<int>, repeaterPath?: list<array{repeater: string, display_row: int}>}> $issues
     */
    private static function navigationTargetsAgree(array $issues): bool
    {
        $expected = null;
        foreach ($issues as $issue) {
            $target = array(
                EditorFieldNavigation::layoutFromItem($issue),
                EditorFieldNavigation::affectedRowsFromItem($issue),
                EditorFieldNavigation::repeaterPathFromItem($issue),
            );
            if ($expected === null) {
                $expected = $target;
                continue;
            }
            if ($target !== $expected) {
                return false;
            }
        }

        return $expected !== null;
    }

    /**
     * @param array{message?: string, label?: string, fieldKey?: string, layout?: string, affectedRows?: list<int>, repeaterPath?: list<array{repeater: string, display_row: int}>} $issue
     * @return array{layout?: string, displayRow?: int, repeaterPath?: list<array{repeater: string, display_row: int}>}
     */
    private static function extrasFromIssue(array $issue): array
    {
        $extra  = array();
        $layout = EditorFieldNavigation::layoutFromItem($issue);
        if ($layout !== '') {
            $extra['layout'] = $layout;
        }

        $rows = EditorFieldNavigation::affectedRowsFromItem($issue);
        if (count($rows) === 1) {
            $extra['displayRow'] = $rows[0];
        }

        $path = EditorFieldNavigation::repeaterPathFromItem($issue);
        if ($path !== array()) {
            $extra['repeaterPath'] = $path;
        }

        return $extra;
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
        if (isset($_GET['post'])) {
            return absint(wp_unslash((string) $_GET['post']));
        }

        if (isset($GLOBALS['post']) && is_object($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            return (int) $GLOBALS['post']->ID;
        }

        return 0;
    }

    /**
     * Read-only editor navigation query. Not a nonce-protected mutation.
     *
     * @return array<string, int|string>
     */
    private function editorNavigationQuery(): array
    {
        $request = array();
        $runArg  = EditorFieldNavigation::AUDIT_RUN_ARG;
        $fieldArg = EditorFieldNavigation::QUERY_ARG;

        if (isset($_GET[$runArg])) {
            $request[$runArg] = sanitize_text_field(wp_unslash((string) $_GET[$runArg]));
        } elseif (isset($_POST[$runArg])) {
            $request[$runArg] = sanitize_text_field(wp_unslash((string) $_POST[$runArg]));
        }

        if (isset($_GET[$fieldArg])) {
            $field = sanitize_text_field(wp_unslash((string) $_GET[$fieldArg]));
            if (EditorFieldNavigation::isQueryTarget($field)) {
                $request[$fieldArg] = $field;
            }
        }

        return $request;
    }

    /**
     * Query string from an editor redirect referer.
     */
    private static function refererQueryString(string $referer): string
    {
        if (function_exists('wp_parse_url')) {
            $query = wp_parse_url($referer, PHP_URL_QUERY);
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url is unavailable (unit tests without WP HTTP API).
            $query = parse_url($referer, PHP_URL_QUERY);
        }

        return is_string($query) ? $query : '';
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
