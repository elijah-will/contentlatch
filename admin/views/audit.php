<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * ContentLatch Audit admin view.
 *
 * @package ContentLatch
 *
 * @var \ContentLatch\Application\Audit\AuditRun|null $active
 * @var \ContentLatch\Application\Audit\AuditRun|null $latestComplete
 * @var \ContentLatch\Application\Audit\AuditRun|null $latestRun
 * @var \ContentLatch\Application\Audit\AuditRun|null $resultsRun
 * @var bool $viewingHistory
 * @var \ContentLatch\Application\Audit\AuditFindingQuery|null $query
 * @var \ContentLatch\Application\Audit\AuditFinding[] $findings
 * @var int $findingTotal
 * @var int $affectedPosts
 * @var array{fail: int, warning: int} $severityCounts
 * @var int $allFindingTotal
 * @var int $allAffectedPosts
 * @var string[] $postTypes
 * @var int $activeRuleCount
 * @var \ContentLatch\Application\Audit\AuditRuleImpact[] $ruleImpacts
 * @var array<string, string> $ruleNames
 * @var array<string, string> $fieldLabels
 * @var \ContentLatch\Application\Audit\AuditRun[] $history
 * @var int $historyTotal
 * @var int $historyPaged
 * @var int $historyTotalPages
 * @var array<string, string> $historyArgs
 * @var int $paged
 * @var int $totalPages
 * @var array<string, string> $filterArgs
 */

defined('ABSPATH') || exit;

use ContentLatch\Admin\AdminView;
use ContentLatch\Admin\AuditPage;
use ContentLatch\Application\AdminPresentation;
use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Admin\AuditAdminRequest;
use ContentLatch\Application\Audit\AuditFindingGroup;
use ContentLatch\Application\AuditPresentation;
use ContentLatch\Application\EditorFieldNavigation;
use ContentLatch\Application\StatusPresentation;

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
<div class="wrap contentlatch<?php echo $viewingHistory ? ' contentlatch-audit--history' : ''; ?>" id="contentlatch-audit">
    <?php
    AdminView::partial(
        'page-header',
        array(
            'title'       => __('Audit', 'contentlatch'),
            'description' => __('Check your existing content against your active rules.', 'contentlatch'),
            'primary'     => array(
                'label'    => __('Run Audit', 'contentlatch'),
                'class'    => 'contentlatch-audit-start',
                'attrs'    => array(
                    'id' => 'contentlatch-audit-start',
                ),
                'disabled' => !$canStart,
            ),
        )
    );
    ?>

    <div id="contentlatch-audit-client-notice" class="notice notice-error inline" hidden>
        <p class="contentlatch-notice-message"></p>
    </div>

    <section class="contentlatch-panel contentlatch-audit-action" aria-labelledby="contentlatch-audit-action-heading">
        <h2 class="contentlatch-builder-section__title" id="contentlatch-audit-action-heading"><?php echo esc_html__('Check your content', 'contentlatch'); ?></h2>
        <p><?php echo esc_html__('Check your existing published and private content against your active rules. ContentLatch only reports issues. It does not change your content.', 'contentlatch'); ?></p>
        <dl class="contentlatch-audit-meta">
            <div>
                <dt><?php echo esc_html__('Applies to', 'contentlatch'); ?></dt>
                <dd><?php echo $typeNames === array() ? esc_html__('None with active rules', 'contentlatch') : esc_html(implode(', ', $typeNames)); ?></dd>
            </div>
            <div>
                <dt><?php echo esc_html__('Active rules', 'contentlatch'); ?></dt>
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
                        'label' => __('Go to Rules', 'contentlatch'),
                        'href'  => admin_url('admin.php?page=contentlatch'),
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
                        'label' => __('Run Audit', 'contentlatch'),
                        'class' => 'contentlatch-audit-start',
                    ),
                )
            );
            ?>
        <?php endif; ?>
    </section>

    <section
        class="contentlatch-panel contentlatch-audit-progress"
        id="contentlatch-audit-active"
        <?php echo $isRunning ? '' : 'hidden'; ?>
        aria-live="polite"
        tabindex="-1"
    >
        <h2 class="contentlatch-builder-section__title" id="contentlatch-audit-running-heading"><?php echo esc_html(AuditPresentation::runningHeading()); ?></h2>
        <p class="contentlatch-audit-progress__status">
            <span id="contentlatch-audit-status"><?php echo esc_html($isRunning ? StatusPresentation::label(StatusPresentation::fromAuditRunStatus($active->status)) : StatusPresentation::label('running')); ?></span>
        </p>
        <p class="contentlatch-audit-progress__count">
            <span id="contentlatch-audit-count-text"><?php echo esc_html(AuditPresentation::progressLabel($isRunning ? $active->postsScanned : 0, $isRunning ? $active->postsTotal : 0)); ?></span>
            <span id="contentlatch-audit-scanned" hidden><?php echo esc_html((string) ($isRunning ? $active->postsScanned : 0)); ?></span>
            <span id="contentlatch-audit-total" hidden><?php echo esc_html((string) ($isRunning ? $active->postsTotal : 0)); ?></span>
        </p>
        <div
            class="contentlatch-progress"
            id="contentlatch-audit-progressbar"
            role="progressbar"
            aria-labelledby="contentlatch-audit-running-heading"
            aria-valuemin="0"
            <?php if ($progressPct !== null) : ?>
                aria-valuemax="100"
                aria-valuenow="<?php echo esc_attr((string) $progressPct); ?>"
            <?php else : ?>
                aria-busy="true"
            <?php endif; ?>
        >
            <div class="contentlatch-progress__bar" id="contentlatch-audit-progress-bar" style="<?php echo $progressPct !== null ? 'width:' . (int) $progressPct . '%' : ''; ?>"></div>
        </div>
        <p class="contentlatch-audit-progress__percent" id="contentlatch-audit-progress-wrap" <?php echo $progressPct === null ? 'hidden' : ''; ?>>
            <span id="contentlatch-audit-progress"><?php echo esc_html((string) ($progressPct ?? '')); ?></span>%
        </p>
        <p class="description contentlatch-audit-progress__issues">
            <span id="contentlatch-audit-failed"><?php echo esc_html((string) ($isRunning ? $active->postsFailed : 0)); ?></span>
            <?php echo esc_html__('need attention', 'contentlatch'); ?>
            ·
            <span id="contentlatch-audit-warned"><?php echo esc_html((string) ($isRunning ? $active->postsWarned : 0)); ?></span>
            <?php echo esc_html__('need review', 'contentlatch'); ?>
        </p>
        <div class="contentlatch-audit-progress__actions">
            <button
                type="button"
                class="button"
                id="contentlatch-audit-cancel"
                data-run="<?php echo $isRunning ? esc_attr((string) $active->id) : ''; ?>"
            >
                <?php echo esc_html__('Cancel Audit', 'contentlatch'); ?>
            </button>
            <div id="contentlatch-audit-cancel-confirm" class="contentlatch-audit-confirm" hidden>
                <p><?php echo esc_html(AuditPresentation::cancelConfirmText()); ?></p>
                <button type="button" class="button button-primary" id="contentlatch-audit-cancel-confirm-yes">
                    <?php echo esc_html__('Stop audit', 'contentlatch'); ?>
                </button>
                <button type="button" class="button" id="contentlatch-audit-cancel-confirm-no">
                    <?php echo esc_html__('Keep running', 'contentlatch'); ?>
                </button>
            </div>
        </div>
    </section>

    <?php if ($active === null && $latestRun !== null && $latestRun->status === AuditRunStatus::Failed) : ?>
        <section class="contentlatch-panel contentlatch-audit-outcome contentlatch-audit-outcome--failed" id="contentlatch-audit-outcome" tabindex="-1">
            <h2 class="contentlatch-builder-section__title"><?php echo esc_html(AuditPresentation::failedHeading()); ?></h2>
            <p><?php echo esc_html($latestRun->errorMessage !== null && $latestRun->errorMessage !== '' ? $latestRun->errorMessage : AuditPresentation::failedFallbackMessage()); ?></p>
            <p class="description"><?php echo esc_html__('This attempt was not used as the latest completed result.', 'contentlatch'); ?></p>
            <p>
                <button type="button" class="button button-primary contentlatch-audit-start" id="contentlatch-audit-retry" <?php disabled(!$canStart); ?>>
                    <?php echo esc_html__('Try again', 'contentlatch'); ?>
                </button>
            </p>
        </section>
    <?php elseif ($active === null && $latestRun !== null && $latestRun->status === AuditRunStatus::Cancelled) : ?>
        <section class="contentlatch-panel contentlatch-audit-outcome contentlatch-audit-outcome--cancelled" id="contentlatch-audit-outcome" tabindex="-1">
            <h2 class="contentlatch-builder-section__title"><?php echo esc_html(AuditPresentation::cancelledHeading()); ?></h2>
            <p><?php echo esc_html(AuditPresentation::cancelledText()); ?></p>
        </section>
    <?php endif; ?>

    <?php if ($resultsRun !== null) : ?>
    <h2 id="contentlatch-audit-results-heading">
        <?php echo esc_html($viewingHistory ? __('Previous audit', 'contentlatch') : AuditPresentation::completedHeading()); ?>
        <?php if ($viewingHistory && $completedAt !== '') : ?>
            <span class="contentlatch-history-date"><?php echo esc_html($completedAt); ?></span>
        <?php endif; ?>
    </h2>
    <?php endif; ?>
    <?php if ($resultsRun === null) : ?>
        <?php if (!$isFirstRun && $active === null) : ?>
            <p><?php echo esc_html__('No completed audit results yet.', 'contentlatch'); ?></p>
        <?php endif; ?>
    <?php else : ?>
        <?php if ($viewingHistory) : ?>
            <div class="contentlatch-history-banner" role="status">
                <p class="contentlatch-history-banner__kicker"><?php echo esc_html__('Previous audit', 'contentlatch'); ?></p>
                <p class="contentlatch-history-banner__title">
                    <?php echo esc_html(AuditPresentation::viewingResultsFrom($completedAt)); ?>
                </p>
                <p class="contentlatch-history-banner__notice"><?php echo esc_html(AuditPresentation::historicalNotice()); ?></p>
                <p class="contentlatch-history-banner__action">
                    <a class="button button-primary" href="<?php echo esc_url($auditUrl(AuditPage::latestResultsArgs())); ?>">
                        <?php echo esc_html(AuditPresentation::backToLatestLabel()); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <div class="contentlatch-health">
            <?php if (!$viewingHistory) : ?>
                <p>
                    <?php
                    echo esc_html(
                        $completedAt !== ''
                            ? sprintf(
                                /* translators: %s: completed date/time */
                                __('Last completed: %s', 'contentlatch'),
                                $completedAt
                            )
                            : __('Last completed audit', 'contentlatch')
                    );
                    ?>
                    &nbsp;|&nbsp;
                    <?php echo esc_html__('Status:', 'contentlatch'); ?>
                    <strong><?php echo esc_html__('Completed', 'contentlatch'); ?></strong>
                    <?php if ($resultsRun->postTypes !== array()) : ?>
                        &nbsp;|&nbsp;
                        <?php echo esc_html__('Post types:', 'contentlatch'); ?>
                        <strong><?php echo esc_html(implode(', ', $resultsRun->postTypes)); ?></strong>
                    <?php endif; ?>
                    &nbsp;|&nbsp;
                    <?php echo esc_html__('Active rules (current):', 'contentlatch'); ?>
                    <strong><?php echo esc_html((string) $activeRuleCount); ?></strong>
                </p>
            <?php else : ?>
                <p>
                    <?php echo esc_html__('Status:', 'contentlatch'); ?>
                    <strong><?php echo esc_html__('Completed', 'contentlatch'); ?></strong>
                    <?php if ($resultsRun->postTypes !== array()) : ?>
                        &nbsp;|&nbsp;
                        <?php echo esc_html__('Post types:', 'contentlatch'); ?>
                        <strong><?php echo esc_html(implode(', ', $resultsRun->postTypes)); ?></strong>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <h3 class="contentlatch-health__group"><?php echo esc_html__('Content items', 'contentlatch'); ?></h3>
            <ul class="contentlatch-health__stats">
                <li class="contentlatch-health__stat" title="<?php echo esc_attr__('How many posts, pages, or other content items this audit checked.', 'contentlatch'); ?>">
                    <span class="contentlatch-health__value"><?php echo esc_html((string) $resultsRun->postsScanned); ?></span>
                    <span class="contentlatch-health__label"><?php echo esc_html__('Checked', 'contentlatch'); ?></span>
                </li>
                <li class="contentlatch-health__stat contentlatch-health__stat--passed" title="<?php echo esc_attr__('Content items that met every applicable rule.', 'contentlatch'); ?>">
                    <span class="contentlatch-health__value"><?php echo esc_html((string) $resultsRun->postsPassed); ?></span>
                    <span class="contentlatch-health__label"><?php echo esc_html__('Passed', 'contentlatch'); ?></span>
                </li>
                <li class="contentlatch-health__stat contentlatch-health__stat--failed" title="<?php echo esc_attr__('Content items with at least one blocking rule problem.', 'contentlatch'); ?>">
                    <span class="contentlatch-health__value"><?php echo esc_html((string) $resultsRun->postsFailed); ?></span>
                    <span class="contentlatch-health__label"><?php echo esc_html__('Need attention', 'contentlatch'); ?></span>
                </li>
                <li class="contentlatch-health__stat contentlatch-health__stat--warning" title="<?php echo esc_attr__('Content items with warnings only. These do not block publishing.', 'contentlatch'); ?>">
                    <span class="contentlatch-health__value"><?php echo esc_html((string) $resultsRun->postsWarned); ?></span>
                    <span class="contentlatch-health__label"><?php echo esc_html__('Need review', 'contentlatch'); ?></span>
                </li>
                <?php if ($resultsRun->postsNotEvaluated > 0) : ?>
                    <li class="contentlatch-health__stat" title="<?php echo esc_attr__('Rules did not apply to this content.', 'contentlatch'); ?>">
                        <span class="contentlatch-health__value"><?php echo esc_html((string) $resultsRun->postsNotEvaluated); ?></span>
                        <span class="contentlatch-health__label"><?php echo esc_html__('Not checked', 'contentlatch'); ?></span>
                    </li>
                <?php endif; ?>
            </ul>
            <p class="description contentlatch-health__help">
                <?php echo esc_html__('Each number is one post, page, or other piece of content.', 'contentlatch'); ?>
            </p>

            <h3 class="contentlatch-health__group"><?php echo esc_html__('Issues', 'contentlatch'); ?></h3>
            <ul class="contentlatch-findings-stats">
                <li class="contentlatch-health__stat contentlatch-health__stat--findings" title="<?php echo esc_attr__('Total rule problems found. One content item can have several issues.', 'contentlatch'); ?>">
                    <span class="contentlatch-health__value"><?php echo esc_html((string) $allFindingTotal); ?></span>
                    <span class="contentlatch-health__label"><?php echo esc_html__('Issues found', 'contentlatch'); ?></span>
                </li>
                <li class="contentlatch-health__stat contentlatch-health__stat--failed" title="<?php echo esc_attr__('Rule problems that prevent publishing.', 'contentlatch'); ?>">
                    <span class="contentlatch-health__value"><?php echo esc_html((string) $severityCounts['fail']); ?></span>
                    <span class="contentlatch-health__label"><?php echo esc_html__('Blocking issues', 'contentlatch'); ?></span>
                </li>
                <li class="contentlatch-health__stat contentlatch-health__stat--warning" title="<?php echo esc_attr__('Rule problems that need review but do not block publishing.', 'contentlatch'); ?>">
                    <span class="contentlatch-health__value"><?php echo esc_html((string) $severityCounts['warning']); ?></span>
                    <span class="contentlatch-health__label"><?php echo esc_html__('Warnings', 'contentlatch'); ?></span>
                </li>
                <li class="contentlatch-health__stat contentlatch-health__stat--findings" title="<?php echo esc_attr__('How many content items have at least one issue.', 'contentlatch'); ?>">
                    <span class="contentlatch-health__value"><?php echo esc_html((string) $allAffectedPosts); ?></span>
                    <span class="contentlatch-health__label"><?php echo esc_html__('Content items affected', 'contentlatch'); ?></span>
                </li>
            </ul>
            <p class="description contentlatch-health__help">
                <?php echo esc_html__('An issue is one rule problem. One content item can have several issues. Blocking issues prevent publishing. Warnings do not.', 'contentlatch'); ?>
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
            <h2 id="contentlatch-findings-heading"><?php echo esc_html(AuditPresentation::findingsHeading()); ?></h2>
            <?php
            $severityFilter = $query !== null ? (string) $query->severity : '';
            $historyPaged   = isset($historyPaged) ? (int) $historyPaged : 1;
            $clearFilters   = $auditUrl(
                AuditPage::clearFilterArgs($viewingHistory ? (int) $resultsRun->id : null, $historyPaged)
            ) . '#contentlatch-findings-heading';
            ?>
            <form method="get" class="contentlatch-filters" action="<?php echo esc_url(admin_url('admin.php')); ?>#contentlatch-findings-heading">
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
                <div class="contentlatch-filters__severity" role="group" aria-labelledby="contentlatch-filter-severity-label">
                    <span class="contentlatch-filters__label" id="contentlatch-filter-severity-label"><?php echo esc_html(AuditPresentation::severityFilterLabel()); ?></span>
                    <div class="contentlatch-filter-chips">
                        <button
                            type="submit"
                            name="severity"
                            value=""
                            class="contentlatch-filter-chip<?php echo $severityFilter === '' ? ' is-selected' : ''; ?>"
                            aria-pressed="<?php echo $severityFilter === '' ? 'true' : 'false'; ?>"
                        >
                            <?php echo esc_html__('All', 'contentlatch'); ?>
                        </button>
                        <button
                            type="submit"
                            name="severity"
                            value="fail"
                            class="contentlatch-filter-chip<?php echo $severityFilter === 'fail' ? ' is-selected' : ''; ?>"
                            aria-pressed="<?php echo $severityFilter === 'fail' ? 'true' : 'false'; ?>"
                        >
                            <?php echo esc_html__('Blocking', 'contentlatch'); ?>
                        </button>
                        <button
                            type="submit"
                            name="severity"
                            value="warning"
                            class="contentlatch-filter-chip<?php echo $severityFilter === 'warning' ? ' is-selected' : ''; ?>"
                            aria-pressed="<?php echo $severityFilter === 'warning' ? 'true' : 'false'; ?>"
                        >
                            <?php echo esc_html__('Warning', 'contentlatch'); ?>
                        </button>
                    </div>
                </div>
                <label class="contentlatch-filters__field">
                    <span class="contentlatch-filters__label"><?php echo esc_html(AuditPresentation::ruleFilterLabel()); ?></span>
                    <select name="rule">
                        <option value=""><?php echo esc_html__('All rules', 'contentlatch'); ?></option>
                        <?php foreach ($ruleImpacts as $impact) : ?>
                            <option value="<?php echo esc_attr((string) $impact->ruleId); ?>" <?php selected($query !== null && (string) $query->ruleId === (string) $impact->ruleId); ?>>
                                <?php echo esc_html($ruleNames[(string) $impact->ruleId] ?? AuditPresentation::ruleName(null, $impact->ruleId)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="contentlatch-filters__field">
                    <span class="contentlatch-filters__label"><?php echo esc_html(AuditPresentation::contentTypeFilterLabel()); ?></span>
                    <select name="<?php echo esc_attr(AuditAdminRequest::TYPE_QUERY_ARG); ?>">
                        <option value=""><?php echo esc_html__('All types', 'contentlatch'); ?></option>
                        <?php foreach ($resultsRun->postTypes as $type) : ?>
                            <option value="<?php echo esc_attr($type); ?>" <?php selected($query !== null && $query->postType === $type); ?>>
                                <?php echo esc_html(AdminPresentation::postTypeLabel($type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="contentlatch-filters__actions">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Filter', 'contentlatch'); ?></button>
                    <a class="contentlatch-button--link contentlatch-filters__clear" href="<?php echo esc_url($clearFilters); ?>">
                        <?php echo esc_html__('Clear filters', 'contentlatch'); ?>
                    </a>
                </div>
            </form>
            <?php if ($findingTotal > 0) : ?>
                <p class="contentlatch-filters__range"><?php echo esc_html(AuditPresentation::findingsRangeLabel($paged, AuditPage::PAGE_SIZE, $findingTotal)); ?></p>
            <?php endif; ?>

            <?php if ($query !== null && $query->hasFilters()) : ?>
                <p class="contentlatch-filters__summary">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: filtered issue count, 2: distinct posts in the filtered set */
                            __('Showing %1$d issues for %2$d content items with the current filters.', 'contentlatch'),
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
                            'label' => __('Clear filters', 'contentlatch'),
                            'href'  => $clearFilters,
                        ),
                    )
                );
                ?>
            <?php else : ?>
                <div class="contentlatch-findings" aria-labelledby="contentlatch-findings-heading">
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
                <nav class="contentlatch-pagination contentlatch-pagination--findings" aria-label="<?php echo esc_attr(AuditPresentation::paginationLabel()); ?>" aria-describedby="contentlatch-pagination-status">
                    <p class="contentlatch-pagination__status" id="contentlatch-pagination-status">
                        <?php echo esc_html(AuditPresentation::findingsRangeLabel($paged, AuditPage::PAGE_SIZE, $findingTotal)); ?>
                    </p>
                    <div class="contentlatch-pagination__controls">
                        <?php if ($paged <= 1) : ?>
                            <span class="contentlatch-pagination__disabled" aria-disabled="true"><?php echo esc_html__('Previous', 'contentlatch'); ?></span>
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
                                    'prev_text' => __('Previous', 'contentlatch'),
                                    'next_text' => __('Next', 'contentlatch'),
                                )
                            );
                        }
                        echo wp_kses_post($pagination);
                        ?>
                        <?php if ($paged >= $totalPages) : ?>
                            <span class="contentlatch-pagination__disabled" aria-disabled="true"><?php echo esc_html__('Next', 'contentlatch'); ?></span>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <section class="contentlatch-history contentlatch-history--wide" aria-labelledby="contentlatch-history-heading">
        <details class="contentlatch-history__details"<?php echo $historyOpen ? ' open' : ''; ?>>
            <summary class="contentlatch-history__summary" aria-expanded="<?php echo $historyOpen ? 'true' : 'false'; ?>">
                <h2 id="contentlatch-history-heading"><?php echo esc_html(AuditPresentation::historyHeading()); ?></h2>
                <span class="contentlatch-history__toggle">
                    <span class="contentlatch-history__toggle-show"><?php echo esc_html(AuditPresentation::showHistoryLabel()); ?></span>
                    <span class="contentlatch-history__toggle-hide"><?php echo esc_html(AuditPresentation::hideHistoryLabel()); ?></span>
                </span>
            </summary>
            <div class="contentlatch-history__panel" id="contentlatch-history-panel">
        <?php if ($history === array()) : ?>
            <p class="contentlatch-history__empty"><?php echo esc_html(AuditPresentation::historyEmptyText()); ?></p>
        <?php else : ?>
            <ol class="contentlatch-history__list">
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
                    class="contentlatch-pagination contentlatch-pagination--history"
                    aria-label="<?php echo esc_attr(AuditPresentation::historyPaginationLabel()); ?>"
                    aria-describedby="contentlatch-history-pagination-status"
                >
                    <p class="contentlatch-pagination__status" id="contentlatch-history-pagination-status">
                        <?php echo esc_html(AuditPresentation::historyRangeLabel($historyPaged, AuditPage::HISTORY_PAGE_SIZE, $historyTotal)); ?>
                    </p>
                    <div class="contentlatch-pagination__controls">
                        <?php if ($historyPaged <= 1) : ?>
                            <span class="contentlatch-pagination__disabled" aria-disabled="true"><?php echo esc_html__('Previous', 'contentlatch'); ?></span>
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
                                    'prev_text' => __('Previous', 'contentlatch'),
                                    'next_text' => __('Next', 'contentlatch'),
                                )
                            );
                        }
                        echo wp_kses_post($historyPagination);
                        ?>
                        <?php if ($historyPaged >= $historyTotalPages) : ?>
                            <span class="contentlatch-pagination__disabled" aria-disabled="true"><?php echo esc_html__('Next', 'contentlatch'); ?></span>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
            </div>
        </details>
    </section>
</div>
