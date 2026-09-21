<?php
/**
 * ContentGuard Audit admin page.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

defined('ABSPATH') || exit;

use ContentGuard\Application\Audit\AuditFindingQuery;
use ContentGuard\Application\Audit\AuditRuleImpact;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\AuditPresentation;
use ContentGuard\Application\Exception\AuditException;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Application\StatusPresentation;
use ContentGuard\Infrastructure\WordPress\Capabilities;

final class AuditPage
{
    public const SLUG              = 'contentguard-audit';
    public const PAGE_SIZE         = AuditFindingQuery::DEFAULT_LIMIT;
    public const HISTORY_PAGE_SIZE = 10;
    public const HISTORY_PAGED_ARG = 'hpaged';

    public function __construct(private ContentAuditService $audit, private RuleRepositoryInterface $rules)
    {
    }

    public static function register(ContentAuditService $audit, RuleRepositoryInterface $rules): void
    {
        $page = new self($audit, $rules);
        AuditAdminRequest::register();
        add_action('admin_menu', array($page, 'addMenu'));
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
    }

    public function addMenu(): void
    {
        add_submenu_page(
            RulesPage::SLUG,
            __('Audit', 'contentguard'),
            __('Audit', 'contentguard'),
            Capabilities::MANAGE,
            self::SLUG,
            array($this, 'render')
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== RulesPage::SLUG . '_page_' . self::SLUG && $hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }

        AdminAssets::enqueueShared();

        wp_register_style(
            'contentguard-audit',
            CONTENTGUARD_URL . 'admin/css/audit.css',
            array(AdminAssets::STYLE),
            \ContentGuard\Plugin::VERSION
        );
        wp_enqueue_style('contentguard-audit');

        wp_register_script(
            'contentguard-audit',
            CONTENTGUARD_URL . 'admin/js/audit.js',
            array('wp-i18n'),
            \ContentGuard\Plugin::VERSION,
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('contentguard-audit', 'contentguard', CONTENTGUARD_DIR . 'languages');
        }
        wp_localize_script(
            'contentguard-audit',
            'contentguardAudit',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(ContentAuditService::NONCE_ACTION),
                'actions' => array(
                    'start'  => AuditAjaxController::ACTION_START,
                    'batch'  => AuditAjaxController::ACTION_BATCH,
                    'cancel' => AuditAjaxController::ACTION_CANCEL,
                    'status' => AuditAjaxController::ACTION_STATUS,
                ),
                'i18n'    => array(
                    'pending'          => StatusPresentation::label('pending'),
                    'running'          => StatusPresentation::label('running'),
                    /* translators: 1: Number of content items checked. 2: Total content items. */
                    'progressKnown'    => __('%1$d of %2$d content items checked', 'contentguard'),
                    /* translators: %d: Number of content items checked. */
                    'progressUnknown'  => __('%d content items checked', 'contentguard'),
                    'couldNotStart'    => __('Could not start the audit.', 'contentguard'),
                    'batchFailed'      => __('The audit could not continue.', 'contentguard'),
                    'cancelConfirm'    => AuditPresentation::cancelConfirmText(),
                    'stopAudit'        => __('Stop audit', 'contentguard'),
                    'keepRunning'      => __('Keep running', 'contentguard'),
                    'cancelAudit'      => __('Cancel Audit', 'contentguard'),
                ),
            )
        );
        wp_enqueue_script('contentguard-audit');
    }

    /**
     * Start is available when there are active-rule post types and no healthy active run.
     * Zero eligible posts must not disable start.
     *
     * @param string[] $postTypes
     */
    public static function canStart(?AuditRun $active, array $postTypes): bool
    {
        return $active === null && $postTypes !== array();
    }

    /**
     * Failed and cancelled runs never become current results.
     */
    public static function resolveResultsRun(?AuditRun $latestComplete, ?AuditRun $requested): ?AuditRun
    {
        if ($requested !== null && $requested->status === AuditRunStatus::Complete) {
            return $requested;
        }

        return $latestComplete;
    }

    public static function isViewingHistory(?AuditRun $latestComplete, ?AuditRun $resultsRun): bool
    {
        return $resultsRun !== null
            && $latestComplete !== null
            && $resultsRun->id !== $latestComplete->id;
    }

    public static function healthHeading(bool $viewingHistory): string
    {
        return $viewingHistory ? __('Previous audit', 'contentguard') : AuditPresentation::completedHeading();
    }

    public static function isFirstRun(?AuditRun $resultsRun, ?AuditRun $active): bool
    {
        return $resultsRun === null && $active === null;
    }

    /**
     * @return array{page: string}
     */
    public static function latestResultsArgs(): array
    {
        return array('page' => self::SLUG);
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function requestedRunId(array $request): int
    {
        return isset($request['run']) ? (int) $request['run'] : 0;
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function findingQueryFromRequest(
        int $runId,
        array $request,
        int $pageSize = self::PAGE_SIZE,
    ): AuditFindingQuery {
        $paged = isset($request['paged']) ? (int) $request['paged'] : 1;
        if ($paged < 1) {
            $paged = 1;
        }

        return new AuditFindingQuery(
            $runId,
            self::requestKey($request, 'severity'),
            isset($request['rule']) ? $request['rule'] : null,
            self::requestPostType($request),
            $pageSize,
            ($paged - 1) * $pageSize
        );
    }

    public static function currentPage(AuditFindingQuery $query): int
    {
        return (int) floor($query->offset / $query->limit) + 1;
    }

    public static function totalPages(int $total, int $pageSize = self::PAGE_SIZE): int
    {
        if ($total <= 0) {
            return 1;
        }

        return (int) max(1, (int) ceil($total / $pageSize));
    }

    public static function formatRunTime(?string $gmt): string
    {
        if ($gmt === null || $gmt === '') {
            return '';
        }

        if (function_exists('wp_date')) {
            $timestamp = strtotime($gmt . ' UTC');
            if ($timestamp === false) {
                return $gmt;
            }

            $dateFormat = function_exists('get_option') ? (string) get_option('date_format', 'F j, Y') : 'F j, Y';
            $timeFormat = function_exists('get_option') ? (string) get_option('time_format', 'g:i a') : 'g:i a';

            return (string) wp_date($dateFormat . ' ' . $timeFormat, $timestamp);
        }

        return $gmt;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, string>
     */
    public static function filterArgs(array $request, ?int $runId = null, int $historyPage = 0): array
    {
        $args = array('page' => self::SLUG);

        if ($runId !== null && $runId > 0) {
            $args['run'] = (string) $runId;
        }

        $severity = self::requestKey($request, 'severity');
        if ($severity === 'fail' || $severity === 'warning') {
            $args['severity'] = $severity;
        }

        $rule = isset($request['rule']) ? (string) $request['rule'] : '';
        if ($rule !== '' && $rule !== '0') {
            $args['rule'] = $rule;
        }

        $postType = self::requestPostType($request);
        if ($postType !== '') {
            $args[AuditAdminRequest::TYPE_QUERY_ARG] = $postType;
        }

        $historyPage = $historyPage > 0 ? $historyPage : self::requestedHistoryPage($request);
        if ($historyPage > 1) {
            $args[self::HISTORY_PAGED_ARG] = (string) $historyPage;
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function requestedHistoryPage(array $request): int
    {
        $page = isset($request[self::HISTORY_PAGED_ARG]) ? (int) $request[self::HISTORY_PAGED_ARG] : 1;

        return $page < 1 ? 1 : $page;
    }

    public static function clampPage(int $page, int $totalPages): int
    {
        return min(max(1, $page), max(1, $totalPages));
    }

    /**
     * @param array<string, string> $filterArgs
     * @return array<string, string>
     */
    public static function historyPaginationArgs(array $filterArgs, int $findingsPage): array
    {
        $args = $filterArgs;
        unset($args[self::HISTORY_PAGED_ARG]);
        if ($findingsPage > 1) {
            $args['paged'] = (string) $findingsPage;
        }

        return $args;
    }

    /**
     * @return array<string, string>
     */
    public static function clearFilterArgs(?int $runId, int $historyPage = 1): array
    {
        $args = $runId !== null && $runId > 0
            ? array('page' => self::SLUG, 'run' => (string) $runId)
            : array('page' => self::SLUG);
        if ($historyPage > 1) {
            $args[self::HISTORY_PAGED_ARG] = (string) $historyPage;
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function requestPostType(array $request): string
    {
        $type = self::requestKey($request, AuditAdminRequest::TYPE_QUERY_ARG);
        if ($type !== '') {
            return $type;
        }

        return self::requestKey($request, 'post_type');
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function requestKey(array $request, string $key): string
    {
        $value = isset($request[$key]) ? (string) $request[$key] : '';
        if ($value === '') {
            return '';
        }

        return function_exists('sanitize_key') ? sanitize_key($value) : $value;
    }

    public function render(): void
    {
        if (!Capabilities::currentUserCanManage()) {
            wp_die(esc_html__('You are not allowed to access this page.', 'contentguard'));
        }

        $active         = $this->audit->getActiveRun();
        $latestComplete = $this->audit->getLatestCompleteRun();
        $latestRun      = $this->audit->getLatestRun();
        $requested      = $this->requestedRun(self::requestedRunId($_GET));
        $resultsRun     = self::resolveResultsRun($latestComplete, $requested);
        $viewingHistory = self::isViewingHistory($latestComplete, $resultsRun);
        $query          = $resultsRun !== null
            ? self::findingQueryFromRequest($resultsRun->id, $_GET)
            : null;
        $findings       = $query !== null ? $this->audit->queryFindings($query) : array();
        $findingTotal   = $query !== null ? $this->audit->countFindings($query) : 0;
        $affectedPosts  = $query !== null ? $this->audit->countDistinctPosts($query) : 0;
        $severityCounts = $resultsRun !== null
            ? $this->audit->countFindingsBySeverity($resultsRun->id)
            : array('fail' => 0, 'warning' => 0);
        $unfiltered     = $resultsRun !== null ? new AuditFindingQuery($resultsRun->id) : null;
        $allFindingTotal = $unfiltered !== null ? $this->audit->countFindings($unfiltered) : 0;
        $allAffectedPosts = $unfiltered !== null ? $this->audit->countDistinctPosts($unfiltered) : 0;
        $postTypes      = $this->rules->findActivePostTypes();
        $activeRuleCount = 0;
        foreach ($postTypes as $postType) {
            $activeRuleCount += count($this->rules->findActiveForPostType($postType));
        }
        $ruleImpacts    = $resultsRun !== null ? $this->audit->countFindingsByRule($resultsRun->id) : array();
        $ruleNames      = $this->ruleNames($findings, $ruleImpacts);
        $fieldLabels    = $this->fieldLabels($findings);
        $historyTotal      = $this->audit->countRuns();
        $historyTotalPages = self::totalPages($historyTotal, self::HISTORY_PAGE_SIZE);
        $historyPaged      = self::clampPage(self::requestedHistoryPage($_GET), $historyTotalPages);
        $history           = $this->audit->listRecentRuns(
            self::HISTORY_PAGE_SIZE,
            ($historyPaged - 1) * self::HISTORY_PAGE_SIZE
        );
        $paged          = $query !== null ? self::currentPage($query) : 1;
        $totalPages     = $query !== null ? self::totalPages($findingTotal, $query->limit) : 1;
        $filterArgs     = self::filterArgs($_GET, $viewingHistory ? $resultsRun?->id : null, $historyPaged);
        $historyArgs    = self::historyPaginationArgs($filterArgs, $paged);

        $view = CONTENTGUARD_DIR . 'admin/views/audit.php';
        require $view;
    }

    private function requestedRun(int $runId): ?AuditRun
    {
        if ($runId <= 0) {
            return null;
        }

        try {
            return $this->audit->getRun($runId);
        } catch (AuditException) {
            return null;
        }
    }

    /**
     * @param \ContentGuard\Application\Audit\AuditFinding[] $findings
     * @param AuditRuleImpact[] $impacts
     * @return array<string, string>
     */
    private function ruleNames(array $findings, array $impacts): array
    {
        $ids = array();
        foreach ($findings as $finding) {
            $ids[(string) $finding->ruleId] = $finding->ruleId;
        }
        foreach ($impacts as $impact) {
            $ids[(string) $impact->ruleId] = $impact->ruleId;
        }

        $names = array();
        foreach ($ids as $ruleId) {
            $names[(string) $ruleId] = AuditPresentation::ruleName($this->rules->find($ruleId), $ruleId);
        }

        return $names;
    }

    /**
     * @param \ContentGuard\Application\Audit\AuditFinding[] $findings
     * @return array<string, string>
     */
    private function fieldLabels(array $findings): array
    {
        $labels = array();
        $rules  = array();

        foreach ($findings as $finding) {
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
}
