<?php
/**
 * ContentGuard Audit admin view.
 *
 * @package ContentGuard
 *
 * @var \ContentGuard\Application\Audit\AuditRun|null $active
 * @var \ContentGuard\Application\Audit\AuditRun|null $latestComplete
 * @var \ContentGuard\Application\Audit\AuditRun|null $latestRun
 * @var \ContentGuard\Application\Audit\AuditRun|null $resultsRun
 * @var bool $viewingHistory
 * @var \ContentGuard\Application\Audit\AuditFindingQuery|null $query
 * @var \ContentGuard\Application\Audit\AuditFinding[] $findings
 * @var int $findingTotal
 * @var int $affectedPosts
 * @var array{fail: int, warning: int} $severityCounts
 * @var int $allFindingTotal
 * @var int $allAffectedPosts
 * @var string[] $postTypes
 * @var int $activeRuleCount
 * @var \ContentGuard\Application\Audit\AuditRuleImpact[] $ruleImpacts
 * @var array<string, string> $ruleNames
 * @var array<string, string> $fieldLabels
 * @var \ContentGuard\Application\Audit\AuditRun[] $history
 * @var int $historyTotal
 * @var int $historyPaged
 * @var int $historyTotalPages
 * @var array<string, string> $historyArgs
 * @var int $paged
 * @var int $totalPages
 * @var array<string, string> $filterArgs
 */

defined('ABSPATH') || exit;

use ContentGuard\Admin\AdminView;
use ContentGuard\Admin\AuditPage;
use ContentGuard\Application\AdminPresentation;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Admin\AuditAdminRequest;
use ContentGuard\Application\Audit\AuditFindingGroup;
use ContentGuard\Application\AuditPresentation;
use ContentGuard\Application\EditorFieldNavigation;
use ContentGuard\Application\StatusPresentation;

$auditUrl    = static function (array $args): string {
    return admin_url('admin.php?' . http_build_query($args));
};
$completedAt = $resultsRun !== null
    ? AuditPage::formatRunTime($resultsRun->finishedAt ?? $resultsRun->startedAt)
    : '';
$canStart    = AuditPage::canStart($active, $postTypes);
$isFirstRun  = AuditPage::isFirstRun($resultsRun, $active);
$isRunning   = $active !== null;
$progressPct = $active !== null ? $active->progressPercent() : null;
$history          = isset($history) && is_array($history) ? $history : array();
$historyTotal     = isset($historyTotal) ? (int) $historyTotal : count($history);
$historyPaged     = isset($historyPaged) ? (int) $historyPaged : 1;
$historyTotalPages = isset($historyTotalPages) ? (int) $historyTotalPages : AuditPage::totalPages($historyTotal, AuditPage::HISTORY_PAGE_SIZE);
$historyArgs      = isset($historyArgs) && is_array($historyArgs) ? $historyArgs : array('page' => AuditPage::SLUG);
$historyOpen      = $viewingHistory || $historyPaged > 1;
$typeNames   = array();
foreach ($postTypes as $type) {
    $typeNames[] = AdminPresentation::postTypeLabel($type);
}
?>
<div class="wrap contentguard<?php echo $viewingHistory ? ' contentguard-audit--history' : ''; ?>" id="contentguard-audit">
    <?php
    AdminView::partial(
        'page-header',
        array(
            'title'       => __('Audit', 'contentguard'),
            'description' => __('Check your existing content against your active rules.', 'contentguard'),
            'primary'     => array(
                'label'    => __('Run Audit', 'contentguard'),
                'class'    => 'contentguard-audit-start',
                'attrs'    => array(
                    'id' => 'contentguard-audit-start',
                ),
                'disabled' => !$canStart,
            ),
        )
    );
    ?>

    <div id="contentguard-audit-client-notice" class="notice notice-error inline" hidden>
        <p class="contentguard-notice-message"></p>
    </div>

    <section class="contentguard-panel contentguard-audit-action" aria-labelledby="contentguard-audit-action-heading">
        <h2 class="contentguard-builder-section__title" id="contentguard-audit-action-heading"><?php echo esc_html__('Check your content', 'contentguard'); ?></h2>
        <p><?php echo esc_html__('Check your existing published and private content against your active rules. ContentGuard only reports issues. It does not change your content.', 'contentguard'); ?></p>
        <dl class="contentguard-audit-meta">
            <div>
                <dt><?php echo esc_html__('Applies to', 'contentguard'); ?></dt>
                <dd><?php echo $typeNames === array() ? esc_html__('None with active rules', 'contentguard') : esc_html(implode(', ', $typeNames)); ?></dd>
            </div>
            <div>
                <dt><?php echo esc_html__('Active rules', 'contentguard'); ?></dt>
                <dd><?php echo esc_html((string) $activeRuleCount); ?></dd>
            </div>
        </dl>

        <?php if (!$canStart && $postTypes === array()) : ?>
            <?php
            AdminView::partial(
                'empty-state',
                array(
                    'heading'   => AuditPresentation::noActiveRulesHeading(),
                    'text'      => AuditPresentation::noActiveRulesText(),
                    'secondary' => array(
                        'label' => __('Go to Rules', 'contentguard'),
                        'href'  => admin_url('admin.php?page=contentguard'),
                    ),
                )
            );
            ?>
        <?php elseif ($isFirstRun && $canStart && $latestRun === null) : ?>
            <?php
            AdminView::partial(
                'empty-state',
                array(
                    'heading' => AuditPresentation::firstRunHeading(),
                    'text'    => AuditPresentation::firstRunText(),
                    'note'    => AuditPresentation::doesNotModifyContent() . ' ' . AuditPresentation::firstRunOutcome(),
                    'primary' => array(
                        'label' => __('Run Audit', 'contentguard'),
                        'class' => 'contentguard-audit-start',
                    ),
                )
            );
            ?>
        <?php endif; ?>
    </section>

    <section
        class="contentguard-panel contentguard-audit-progress"
        id="contentguard-audit-active"
        <?php echo $isRunning ? '' : 'hidden'; ?>
        aria-live="polite"
        tabindex="-1"
    >
        <h2 class="contentguard-builder-section__title" id="contentguard-audit-running-heading"><?php echo esc_html(AuditPresentation::runningHeading()); ?></h2>
        <p class="contentguard-audit-progress__status">
            <span id="contentguard-audit-status"><?php echo esc_html($isRunning ? StatusPresentation::label(StatusPresentation::fromAuditRunStatus($active->status)) : StatusPresentation::label('running')); ?></span>
        </p>
        <p class="contentguard-audit-progress__count">
            <span id="contentguard-audit-count-text"><?php echo esc_html(AuditPresentation::progressLabel($isRunning ? $active->postsScanned : 0, $isRunning ? $active->postsTotal : 0)); ?></span>
            <span id="contentguard-audit-scanned" hidden><?php echo esc_html((string) ($isRunning ? $active->postsScanned : 0)); ?></span>
            <span id="contentguard-audit-total" hidden><?php echo esc_html((string) ($isRunning ? $active->postsTotal : 0)); ?></span>
        </p>
        <div
            class="contentguard-progress"
            id="contentguard-audit-progressbar"
            role="progressbar"
            aria-labelledby="contentguard-audit-running-heading"
            aria-valuemin="0"
            <?php if ($progressPct !== null) : ?>
                aria-valuemax="100"
                aria-valuenow="<?php echo esc_attr((string) $progressPct); ?>"
            <?php else : ?>
                aria-busy="true"
            <?php endif; ?>
        >
            <div class="contentguard-progress__bar" id="contentguard-audit-progress-bar" style="<?php echo $progressPct !== null ? 'width:' . (int) $progressPct . '%' : ''; ?>"></div>
        </div>
        <p class="contentguard-audit-progress__percent" id="contentguard-audit-progress-wrap" <?php echo $progressPct === null ? 'hidden' : ''; ?>>
            <span id="contentguard-audit-progress"><?php echo esc_html((string) ($progressPct ?? '')); ?></span>%
        </p>
        <p class="description contentguard-audit-progress__issues">
            <span id="contentguard-audit-failed"><?php echo esc_html((string) ($isRunning ? $active->postsFailed : 0)); ?></span>
            <?php echo esc_html__('need attention', 'contentguard'); ?>
            ·
            <span id="contentguard-audit-warned"><?php echo esc_html((string) ($isRunning ? $active->postsWarned : 0)); ?></span>
            <?php echo esc_html__('need review', 'contentguard'); ?>
        </p>
        <div class="contentguard-audit-progress__actions">
            <button
                type="button"
                class="button"
                id="contentguard-audit-cancel"
                data-run="<?php echo $isRunning ? esc_attr((string) $active->id) : ''; ?>"
            >
                <?php echo esc_html__('Cancel Audit', 'contentguard'); ?>
            </button>
            <div id="contentguard-audit-cancel-confirm" class="contentguard-audit-confirm" hidden>
                <p><?php echo esc_html(AuditPresentation::cancelConfirmText()); ?></p>
                <button type="button" class="button button-primary" id="contentguard-audit-cancel-confirm-yes">
                    <?php echo esc_html__('Stop audit', 'contentguard'); ?>
                </button>
                <button type="button" class="button" id="contentguard-audit-cancel-confirm-no">
                    <?php echo esc_html__('Keep running', 'contentguard'); ?>
                </button>
            </div>
        </div>
    </section>

    <?php if ($active === null && $latestRun !== null && $latestRun->status === AuditRunStatus::Failed) : ?>
        <section class="contentguard-panel contentguard-audit-outcome contentguard-audit-outcome--failed" id="contentguard-audit-outcome" tabindex="-1">
            <h2 class="contentguard-builder-section__title"><?php echo esc_html(AuditPresentation::failedHeading()); ?></h2>
            <p><?php echo esc_html($latestRun->errorMessage !== null && $latestRun->errorMessage !== '' ? $latestRun->errorMessage : AuditPresentation::failedFallbackMessage()); ?></p>
            <p class="description"><?php echo esc_html__('This attempt was not used as the latest completed result.', 'contentguard'); ?></p>
            <p>
                <button type="button" class="button button-primary contentguard-audit-start" id="contentguard-audit-retry" <?php disabled(!$canStart); ?>>
                    <?php echo esc_html__('Try again', 'contentguard'); ?>
                </button>
            </p>
        </section>
    <?php elseif ($active === null && $latestRun !== null && $latestRun->status === AuditRunStatus::Cancelled) : ?>
        <section class="contentguard-panel contentguard-audit-outcome contentguard-audit-outcome--cancelled" id="contentguard-audit-outcome" tabindex="-1">
            <h2 class="contentguard-builder-section__title"><?php echo esc_html(AuditPresentation::cancelledHeading()); ?></h2>
            <p><?php echo esc_html(AuditPresentation::cancelledText()); ?></p>
        </section>
    <?php endif; ?>

    <?php if ($resultsRun !== null) : ?>
    <h2 id="contentguard-audit-results-heading">
        <?php echo esc_html($viewingHistory ? __('Previous audit', 'contentguard') : AuditPresentation::completedHeading()); ?>
        <?php if ($viewingHistory && $completedAt !== '') : ?>
            <span class="contentguard-history-date"><?php echo esc_html($completedAt); ?></span>
        <?php endif; ?>
    </h2>
    <?php endif; ?>
    <?php if ($resultsRun === null) : ?>
        <?php if (!$isFirstRun && $active === null) : ?>
            <p><?php echo esc_html__('No completed audit results yet.', 'contentguard'); ?></p>
        <?php endif; ?>
    <?php else : ?>
        <?php if ($viewingHistory) : ?>
            <div class="contentguard-history-banner" role="status">
                <p class="contentguard-history-banner__kicker"><?php echo esc_html__('Previous audit', 'contentguard'); ?></p>
                <p class="contentguard-history-banner__title">
                    <?php echo esc_html(AuditPresentation::viewingResultsFrom($completedAt)); ?>
                </p>
                <p class="contentguard-history-banner__notice"><?php echo esc_html(AuditPresentation::historicalNotice()); ?></p>
                <p class="contentguard-history-banner__action">
                    <a class="button button-primary" href="<?php echo esc_url($auditUrl(AuditPage::latestResultsArgs())); ?>">
                        <?php echo esc_html(AuditPresentation::backToLatestLabel()); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <div class="contentguard-health">
            <?php if (!$viewingHistory) : ?>
                <p>
                    <?php
                    echo esc_html(
                        $completedAt !== ''
                            ? sprintf(
                                /* translators: %s: completed date/time */
                                __('Last completed: %s', 'contentguard'),
                                $completedAt
                            )
                            : __('Last completed audit', 'contentguard')
                    );
                    ?>
                    &nbsp;|&nbsp;
                    <?php echo esc_html__('Status:', 'contentguard'); ?>
                    <strong><?php echo esc_html__('Completed', 'contentguard'); ?></strong>
                    <?php if ($resultsRun->postTypes !== array()) : ?>
                        &nbsp;|&nbsp;
                        <?php echo esc_html__('Post types:', 'contentguard'); ?>
                        <strong><?php echo esc_html(implode(', ', $resultsRun->postTypes)); ?></strong>
                    <?php endif; ?>
                    &nbsp;|&nbsp;
                    <?php echo esc_html__('Active rules (current):', 'contentguard'); ?>
                    <strong><?php echo esc_html((string) $activeRuleCount); ?></strong>
                </p>
            <?php else : ?>
                <p>
                    <?php echo esc_html__('Status:', 'contentguard'); ?>
                    <strong><?php echo esc_html__('Completed', 'contentguard'); ?></strong>
                    <?php if ($resultsRun->postTypes !== array()) : ?>
                        &nbsp;|&nbsp;
                        <?php echo esc_html__('Post types:', 'contentguard'); ?>
                        <strong><?php echo esc_html(implode(', ', $resultsRun->postTypes)); ?></strong>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <h3 class="contentguard-health__group"><?php echo esc_html__('Content items', 'contentguard'); ?></h3>
            <ul class="contentguard-health__stats">
                <li class="contentguard-health__stat" title="<?php echo esc_attr__('How many posts, pages, or other content items this audit checked.', 'contentguard'); ?>">
                    <span class="contentguard-health__value"><?php echo esc_html((string) $resultsRun->postsScanned); ?></span>
                    <span class="contentguard-health__label"><?php echo esc_html__('Checked', 'contentguard'); ?></span>
                </li>
                <li class="contentguard-health__stat contentguard-health__stat--passed" title="<?php echo esc_attr__('Content items that met every applicable rule.', 'contentguard'); ?>">
                    <span class="contentguard-health__value"><?php echo esc_html((string) $resultsRun->postsPassed); ?></span>
                    <span class="contentguard-health__label"><?php echo esc_html__('Passed', 'contentguard'); ?></span>
                </li>
                <li class="contentguard-health__stat contentguard-health__stat--failed" title="<?php echo esc_attr__('Content items with at least one blocking rule problem.', 'contentguard'); ?>">
                    <span class="contentguard-health__value"><?php echo esc_html((string) $resultsRun->postsFailed); ?></span>
                    <span class="contentguard-health__label"><?php echo esc_html__('Need attention', 'contentguard'); ?></span>
                </li>
                <li class="contentguard-health__stat contentguard-health__stat--warning" title="<?php echo esc_attr__('Content items with warnings only. These do not block publishing.', 'contentguard'); ?>">
                    <span class="contentguard-health__value"><?php echo esc_html((string) $resultsRun->postsWarned); ?></span>
                    <span class="contentguard-health__label"><?php echo esc_html__('Need review', 'contentguard'); ?></span>
                </li>
                <?php if ($resultsRun->postsNotEvaluated > 0) : ?>
                    <li class="contentguard-health__stat" title="<?php echo esc_attr__('Rules did not apply to this content.', 'contentguard'); ?>">
                        <span class="contentguard-health__value"><?php echo esc_html((string) $resultsRun->postsNotEvaluated); ?></span>
                        <span class="contentguard-health__label"><?php echo esc_html__('Not checked', 'contentguard'); ?></span>
                    </li>
                <?php endif; ?>
            </ul>
            <p class="description contentguard-health__help">
                <?php echo esc_html__('Each number is one post, page, or other piece of content.', 'contentguard'); ?>
            </p>

            <h3 class="contentguard-health__group"><?php echo esc_html__('Issues', 'contentguard'); ?></h3>
            <ul class="contentguard-findings-stats">
                <li class="contentguard-health__stat contentguard-health__stat--findings" title="<?php echo esc_attr__('Total rule problems found. One content item can have several issues.', 'contentguard'); ?>">
                    <span class="contentguard-health__value"><?php echo esc_html((string) $allFindingTotal); ?></span>
                    <span class="contentguard-health__label"><?php echo esc_html__('Issues found', 'contentguard'); ?></span>
                </li>
                <li class="contentguard-health__stat contentguard-health__stat--failed" title="<?php echo esc_attr__('Rule problems that prevent publishing.', 'contentguard'); ?>">
                    <span class="contentguard-health__value"><?php echo esc_html((string) $severityCounts['fail']); ?></span>
                    <span class="contentguard-health__label"><?php echo esc_html__('Blocking issues', 'contentguard'); ?></span>
                </li>
                <li class="contentguard-health__stat contentguard-health__stat--warning" title="<?php echo esc_attr__('Rule problems that need review but do not block publishing.', 'contentguard'); ?>">
                    <span class="contentguard-health__value"><?php echo esc_html((string) $severityCounts['warning']); ?></span>
                    <span class="contentguard-health__label"><?php echo esc_html__('Warnings', 'contentguard'); ?></span>
                </li>
                <li class="contentguard-health__stat contentguard-health__stat--findings" title="<?php echo esc_attr__('How many content items have at least one issue.', 'contentguard'); ?>">
                    <span class="contentguard-health__value"><?php echo esc_html((string) $allAffectedPosts); ?></span>
                    <span class="contentguard-health__label"><?php echo esc_html__('Content items affected', 'contentguard'); ?></span>
                </li>
            </ul>
            <p class="description contentguard-health__help">
                <?php echo esc_html__('An issue is one rule problem. One content item can have several issues. Blocking issues prevent publishing. Warnings do not.', 'contentguard'); ?>
            </p>
        </div>

        <?php if ($allFindingTotal === 0) : ?>
            <?php
            AdminView::partial(
                'empty-state',
                array(
                    'heading' => AuditPresentation::allClearHeading(),
                    'text'    => AuditPresentation::allClearText($resultsRun->postsScanned),
                )
            );
            ?>
        <?php else : ?>
            <h2 id="contentguard-findings-heading"><?php echo esc_html(AuditPresentation::findingsHeading()); ?></h2>
            <?php
            $severityFilter = $query !== null ? (string) $query->severity : '';
            $historyPaged   = isset($historyPaged) ? (int) $historyPaged : 1;
            $clearFilters   = $auditUrl(
                AuditPage::clearFilterArgs($viewingHistory ? (int) $resultsRun->id : null, $historyPaged)
            ) . '#contentguard-findings-heading';
            ?>
            <form method="get" class="contentguard-filters" action="<?php echo esc_url(admin_url('admin.php')); ?>#contentguard-findings-heading">
                <input type="hidden" name="page" value="<?php echo esc_attr(AuditPage::SLUG); ?>">
                <?php if ($viewingHistory) : ?>
                    <input type="hidden" name="run" value="<?php echo esc_attr((string) $resultsRun->id); ?>">
                <?php endif; ?>
                <?php if ($historyPaged > 1) : ?>
                    <input type="hidden" name="<?php echo esc_attr(AuditPage::HISTORY_PAGED_ARG); ?>" value="<?php echo esc_attr((string) $historyPaged); ?>">
                <?php endif; ?>
                <?php if ($severityFilter === 'fail' || $severityFilter === 'warning') : ?>
                    <input type="hidden" name="severity" value="<?php echo esc_attr($severityFilter); ?>">
                <?php endif; ?>
                <div class="contentguard-filters__severity" role="group" aria-labelledby="contentguard-filter-severity-label">
                    <span class="contentguard-filters__label" id="contentguard-filter-severity-label"><?php echo esc_html(AuditPresentation::severityFilterLabel()); ?></span>
                    <div class="contentguard-filter-chips">
                        <button
                            type="submit"
                            name="severity"
                            value=""
                            class="contentguard-filter-chip<?php echo $severityFilter === '' ? ' is-selected' : ''; ?>"
                            aria-pressed="<?php echo $severityFilter === '' ? 'true' : 'false'; ?>"
                        >
                            <?php echo esc_html__('All', 'contentguard'); ?>
                        </button>
                        <button
                            type="submit"
                            name="severity"
                            value="fail"
                            class="contentguard-filter-chip<?php echo $severityFilter === 'fail' ? ' is-selected' : ''; ?>"
                            aria-pressed="<?php echo $severityFilter === 'fail' ? 'true' : 'false'; ?>"
                        >
                            <?php echo esc_html__('Blocking', 'contentguard'); ?>
                        </button>
                        <button
                            type="submit"
                            name="severity"
                            value="warning"
                            class="contentguard-filter-chip<?php echo $severityFilter === 'warning' ? ' is-selected' : ''; ?>"
                            aria-pressed="<?php echo $severityFilter === 'warning' ? 'true' : 'false'; ?>"
                        >
                            <?php echo esc_html__('Warning', 'contentguard'); ?>
                        </button>
                    </div>
                </div>
                <label class="contentguard-filters__field">
                    <span class="contentguard-filters__label"><?php echo esc_html(AuditPresentation::ruleFilterLabel()); ?></span>
                    <select name="rule">
                        <option value=""><?php echo esc_html__('All rules', 'contentguard'); ?></option>
                        <?php foreach ($ruleImpacts as $impact) : ?>
                            <option value="<?php echo esc_attr((string) $impact->ruleId); ?>" <?php selected($query !== null && (string) $query->ruleId === (string) $impact->ruleId); ?>>
                                <?php echo esc_html($ruleNames[(string) $impact->ruleId] ?? AuditPresentation::ruleName(null, $impact->ruleId)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="contentguard-filters__field">
                    <span class="contentguard-filters__label"><?php echo esc_html(AuditPresentation::contentTypeFilterLabel()); ?></span>
                    <select name="<?php echo esc_attr(AuditAdminRequest::TYPE_QUERY_ARG); ?>">
                        <option value=""><?php echo esc_html__('All types', 'contentguard'); ?></option>
                        <?php foreach ($resultsRun->postTypes as $type) : ?>
                            <option value="<?php echo esc_attr($type); ?>" <?php selected($query !== null && $query->postType === $type); ?>>
                                <?php echo esc_html(AdminPresentation::postTypeLabel($type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="contentguard-filters__actions">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Filter', 'contentguard'); ?></button>
                    <a class="contentguard-button--link contentguard-filters__clear" href="<?php echo esc_url($clearFilters); ?>">
                        <?php echo esc_html__('Clear filters', 'contentguard'); ?>
                    </a>
                </div>
            </form>
            <?php if ($findingTotal > 0) : ?>
                <p class="contentguard-filters__range"><?php echo esc_html(AuditPresentation::findingsRangeLabel($paged, AuditPage::PAGE_SIZE, $findingTotal)); ?></p>
            <?php endif; ?>

            <?php if ($query !== null && $query->hasFilters()) : ?>
                <p class="contentguard-filters__summary">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: filtered issue count, 2: distinct posts in the filtered set */
                            __('Showing %1$d issues for %2$d content items with the current filters.', 'contentguard'),
                            $findingTotal,
                            $affectedPosts
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ($findings === array()) : ?>
                <?php
                AdminView::partial(
                    'empty-state',
                    array(
                        'heading'   => AuditPresentation::filteredEmptyHeading(),
                        'text'      => AuditPresentation::filteredEmptyText(),
                        'secondary' => array(
                            'label' => __('Clear filters', 'contentguard'),
                            'href'  => $clearFilters,
                        ),
                    )
                );
                ?>
            <?php else : ?>
                <div class="contentguard-findings" aria-labelledby="contentguard-findings-heading">
                    <?php foreach (AuditFindingGroup::group($findings) as $group) : ?>
                        <?php
                        $finding = $group->first();
                        $title   = function_exists('get_the_title') ? (string) get_the_title($finding->postId) : '';
                        $base    = function_exists('get_edit_post_link') ? get_edit_post_link($finding->postId, 'raw') : '';
                        $base    = is_string($base) ? $base : '';
                        $runId   = $resultsRun !== null ? (int) $resultsRun->id : 0;
                        $edit    = $base !== '' ? EditorFieldNavigation::appendToEditUrl($base, $finding->fieldKey, $runId) : '';
                        $field   = $fieldLabels[(string) $finding->ruleId . ':' . $finding->fieldKey] ?? $finding->fieldKey;
                        $fieldIssues = array();
                        foreach ($group->fieldIssues($fieldLabels) as $issue) {
                            $issue['editUrl'] = $base !== ''
                                ? EditorFieldNavigation::appendToEditUrl($base, $issue['fieldKey'], $runId)
                                : '';
                            $fieldIssues[] = $issue;
                        }
                        AdminView::partial(
                            'audit-finding',
                            array(
                                'title'         => $title,
                                'messages'      => $group->messages(),
                                'field'         => $field,
                                'fieldIssues'   => $fieldIssues,
                                'rule'          => $ruleNames[(string) $finding->ruleId] ?? AuditPresentation::ruleName(null, $finding->ruleId),
                                'statuses'      => $group->statuses(),
                                'postType'      => AdminPresentation::postTypeLabel($finding->postType),
                                'editUrl'       => is_string($edit) ? $edit : '',
                                'uid'           => (string) $finding->id,
                                'issueCount'    => $group->count(),
                                'blockingCount' => $group->blockingCount(),
                                'warningCount'  => $group->warningCount(),
                            )
                        );
                        ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1) : ?>
                <nav class="contentguard-pagination contentguard-pagination--findings" aria-label="<?php echo esc_attr(AuditPresentation::paginationLabel()); ?>" aria-describedby="contentguard-pagination-status">
                    <p class="contentguard-pagination__status" id="contentguard-pagination-status">
                        <?php echo esc_html(AuditPresentation::findingsRangeLabel($paged, AuditPage::PAGE_SIZE, $findingTotal)); ?>
                    </p>
                    <div class="contentguard-pagination__controls">
                        <?php if ($paged <= 1) : ?>
                            <span class="contentguard-pagination__disabled" aria-disabled="true"><?php echo esc_html__('Previous', 'contentguard'); ?></span>
                        <?php endif; ?>
                        <?php
                        $pagination = '';
                        if (function_exists('paginate_links')) {
                            $pagination = (string) paginate_links(
                                array(
                                    'base'      => $auditUrl($filterArgs) . '&paged=%#%',
                                    'format'    => '',
                                    'current'   => $paged,
                                    'total'     => $totalPages,
                                    'prev_text' => __('Previous', 'contentguard'),
                                    'next_text' => __('Next', 'contentguard'),
                                )
                            );
                        }
                        echo wp_kses_post($pagination);
                        ?>
                        <?php if ($paged >= $totalPages) : ?>
                            <span class="contentguard-pagination__disabled" aria-disabled="true"><?php echo esc_html__('Next', 'contentguard'); ?></span>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <section class="contentguard-history contentguard-history--wide" aria-labelledby="contentguard-history-heading">
        <details class="contentguard-history__details"<?php echo $historyOpen ? ' open' : ''; ?>>
            <summary class="contentguard-history__summary" aria-expanded="<?php echo $historyOpen ? 'true' : 'false'; ?>">
                <h2 id="contentguard-history-heading"><?php echo esc_html(AuditPresentation::historyHeading()); ?></h2>
                <span class="contentguard-history__toggle">
                    <span class="contentguard-history__toggle-show"><?php echo esc_html(AuditPresentation::showHistoryLabel()); ?></span>
                    <span class="contentguard-history__toggle-hide"><?php echo esc_html(AuditPresentation::hideHistoryLabel()); ?></span>
                </span>
            </summary>
            <div class="contentguard-history__panel" id="contentguard-history-panel">
        <?php if ($history === array()) : ?>
            <p class="contentguard-history__empty"><?php echo esc_html(AuditPresentation::historyEmptyText()); ?></p>
        <?php else : ?>
            <ol class="contentguard-history__list">
                <?php foreach ($history as $run) : ?>
                    <?php
                    $statusKey   = StatusPresentation::fromAuditRunStatus($run->status);
                    $isLatest    = $latestComplete !== null && $run->id === $latestComplete->id;
                    $viewingThis = $viewingHistory && $resultsRun !== null && $run->id === $resultsRun->id;
                    $isComplete  = $run->status === AuditRunStatus::Complete;
                    $actionLabel = '';
                    $actionUrl   = '';
                    if ($isComplete && !$viewingThis) {
                        $actionLabel = AuditPresentation::viewResultsLabel();
                        $actionArgs  = $isLatest
                            ? AuditPage::latestResultsArgs()
                            : array('page' => AuditPage::SLUG, 'run' => (string) $run->id);
                        if ($historyPaged > 1) {
                            $actionArgs[AuditPage::HISTORY_PAGED_ARG] = (string) $historyPaged;
                        }
                        $actionUrl = $auditUrl($actionArgs);
                    }
                    $outcome = '';
                    $detail  = '';
                    if ($isComplete && ($run->postsFailed > 0 || $run->postsWarned > 0)) {
                        $outcome = AuditPresentation::historyContentOutcomeLabel($run->postsFailed, $run->postsWarned);
                    } elseif ($run->status === AuditRunStatus::Failed) {
                        $detail = $run->errorMessage !== null && $run->errorMessage !== ''
                            ? $run->errorMessage
                            : AuditPresentation::failedFallbackMessage();
                    } elseif ($run->status === AuditRunStatus::Cancelled) {
                        $detail = AuditPresentation::cancelledText();
                    }
                    ?>
                    <li>
                        <?php
                        AdminView::partial(
                            'audit-history-item',
                            array(
                                'date'         => AuditPage::formatRunTime($run->finishedAt ?? $run->startedAt),
                                'status'       => $statusKey,
                                'isCurrent'    => $isLatest,
                                'isViewing'    => $viewingThis,
                                'checkedLabel' => AuditPresentation::contentItemsCheckedLabel($run->postsScanned),
                                'outcome'      => $outcome,
                                'detail'       => $detail,
                                'actionLabel'  => $actionLabel,
                                'actionUrl'    => $actionUrl,
                            )
                        );
                        ?>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($historyTotalPages > 1) : ?>
                <nav
                    class="contentguard-pagination contentguard-pagination--history"
                    aria-label="<?php echo esc_attr(AuditPresentation::historyPaginationLabel()); ?>"
                    aria-describedby="contentguard-history-pagination-status"
                >
                    <p class="contentguard-pagination__status" id="contentguard-history-pagination-status">
                        <?php echo esc_html(AuditPresentation::historyRangeLabel($historyPaged, AuditPage::HISTORY_PAGE_SIZE, $historyTotal)); ?>
                    </p>
                    <div class="contentguard-pagination__controls">
                        <?php if ($historyPaged <= 1) : ?>
                            <span class="contentguard-pagination__disabled" aria-disabled="true"><?php echo esc_html__('Previous', 'contentguard'); ?></span>
                        <?php endif; ?>
                        <?php
                        $historyPagination = '';
                        if (function_exists('paginate_links')) {
                            $historyPagination = (string) paginate_links(
                                array(
                                    'base'      => $auditUrl($historyArgs) . '&' . AuditPage::HISTORY_PAGED_ARG . '=%#%',
                                    'format'    => '',
                                    'current'   => $historyPaged,
                                    'total'     => $historyTotalPages,
                                    'prev_text' => __('Previous', 'contentguard'),
                                    'next_text' => __('Next', 'contentguard'),
                                )
                            );
                        }
                        echo wp_kses_post($historyPagination);
                        ?>
                        <?php if ($historyPaged >= $historyTotalPages) : ?>
                            <span class="contentguard-pagination__disabled" aria-disabled="true"><?php echo esc_html__('Next', 'contentguard'); ?></span>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
            </div>
        </details>
    </section>
</div>
