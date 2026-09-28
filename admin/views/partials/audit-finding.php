<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * One audit finding (or grouped findings) as a compact content-first row.
 *
 * @package ContentLatch
 *
 * @var string $title
 * @var string $message
 * @var list<string> $messages
 * @var list<array{label?: string, fieldKey?: string, messages?: list<string>, editUrl?: string}> $fieldIssues
 * @var string $field
 * @var string $rule
 * @var string $status
 * @var list<string> $statuses
 * @var string $postType
 * @var string $editUrl
 * @var string $uid
 * @var int $issueCount
 * @var int $blockingCount
 * @var int $warningCount
 */

defined('ABSPATH') || exit;

use ContentLatch\Admin\AdminView;
use ContentLatch\Application\Audit\AuditRepeaterCoordinates;
use ContentLatch\Application\AuditPresentation;
use ContentLatch\Application\EditorFieldNavigation;

$title    = isset($title) && is_string($title) ? $title : '';
$field    = isset($field) && is_string($field) ? $field : '';
$rule     = isset($rule) && is_string($rule) ? $rule : '';
$postType = isset($postType) && is_string($postType) ? $postType : '';
$editUrl  = isset($editUrl) && is_string($editUrl) ? $editUrl : '';
$uid      = isset($uid) && is_string($uid) && $uid !== '' ? $uid : 'item';
$messages = isset($messages) && is_array($messages) ? array_values(array_filter($messages, 'is_string')) : array();
if ($messages === array() && isset($message) && is_string($message) && $message !== '') {
    $messages = array($message);
}
$statuses = isset($statuses) && is_array($statuses) ? array_values(array_filter($statuses, 'is_string')) : array();
if ($statuses === array() && isset($status) && is_string($status) && $status !== '') {
    $statuses = array($status);
}
$fieldIssues = isset($fieldIssues) && is_array($fieldIssues) ? array_values($fieldIssues) : array();
$issueCount  = isset($issueCount) ? (int) $issueCount : ($fieldIssues !== array()
    ? array_sum(array_map(static fn (array $issue): int => count($issue['messages'] ?? array()), $fieldIssues))
    : count($messages));
$blockingCount = isset($blockingCount) ? (int) $blockingCount : 0;
$warningCount  = isset($warningCount) ? (int) $warningCount : 0;
$display       = AuditPresentation::postTitle($title);
$headingId     = 'contentlatch-finding-title-' . $uid;
$primary       = $statuses[0] ?? 'blocking';
$modifier      = $primary === 'warning' ? 'warning' : 'blocking';
$multiple      = $issueCount > 1;
$countLabel    = AuditPresentation::issuesCountLabel($issueCount);
$summary       = AuditPresentation::groupSummary($messages, $issueCount);
$visibleFields = array();
$allFieldNames = array();
foreach ($fieldIssues as $issue) {
    $issueLabel = AuditPresentation::displayFieldLabel(
        isset($issue['label']) && is_string($issue['label']) ? $issue['label'] : '',
        isset($issue['fieldKey']) && is_string($issue['fieldKey']) ? $issue['fieldKey'] : ''
    );
    if ($issueLabel === '') {
        continue;
    }

    $allFieldNames[] = $issueLabel;
    if (count($visibleFields) < AuditPresentation::VISIBLE_FIELD_LIMIT) {
        $visibleFields[] = $issue + array('label' => $issueLabel);
    }
}
if ($visibleFields === array() && $field !== '') {
    $singleLabel = AuditPresentation::displayFieldLabel($field);
    if ($singleLabel !== '') {
        $allFieldNames[] = $singleLabel;
        $visibleFields[] = array(
            'label'    => $singleLabel,
            'fieldKey' => '',
            'editUrl'  => '',
        );
    }
}
$hiddenFields = max(0, count($allFieldNames) - count($visibleFields));
?>
<article
    class="contentlatch-finding contentlatch-finding--<?php echo esc_attr($modifier); ?><?php echo $multiple ? ' contentlatch-finding--grouped' : ''; ?>"
    aria-labelledby="<?php echo esc_attr($headingId); ?>"
>
    <div class="contentlatch-finding__headline">
        <h3 class="contentlatch-finding__title" id="<?php echo esc_attr($headingId); ?>"><?php echo esc_html($display); ?></h3>
        <?php if ($multiple) : ?>
            <span class="contentlatch-finding__sep" aria-hidden="true">·</span>
            <span class="contentlatch-finding__count"><?php echo esc_html($countLabel); ?></span>
        <?php endif; ?>
        <?php foreach ($statuses as $statusKey) : ?>
            <span class="contentlatch-finding__sep" aria-hidden="true">·</span>
            <?php AdminView::partial('status-pill', array('status' => $statusKey)); ?>
        <?php endforeach; ?>
        <?php if ($multiple && $blockingCount > 0 && $warningCount > 0) : ?>
            <span class="screen-reader-text">
                <?php echo esc_html(AuditPresentation::blockingCountLabel($blockingCount) . ', ' . AuditPresentation::warningCountLabel($warningCount)); ?>
            </span>
        <?php endif; ?>
    </div>
    <?php if ($postType !== '' || $visibleFields !== array()) : ?>
        <p class="contentlatch-finding__meta">
            <?php if ($postType !== '') : ?>
                <span class="contentlatch-finding__type"><?php echo esc_html($postType); ?></span>
            <?php endif; ?>
            <?php if ($allFieldNames !== array()) : ?>
                <span class="screen-reader-text">
                    <?php echo esc_html(sprintf(/* translators: %s: comma-separated field names */ __('Affected fields: %s', 'contentlatch'), implode(', ', $allFieldNames))); ?>
                </span>
            <?php endif; ?>
            <?php foreach ($visibleFields as $index => $issue) : ?>
                <?php
                $issueLabel = (string) $issue['label'];
                $issueKey   = isset($issue['fieldKey']) && is_string($issue['fieldKey']) ? $issue['fieldKey'] : '';
                $issueEdit  = isset($issue['editUrl']) && is_string($issue['editUrl']) ? $issue['editUrl'] : '';
                $canLink    = $issueEdit !== '' && EditorFieldNavigation::isQueryTarget($issueKey);
                ?>
                <?php if ($postType !== '' || $index > 0) : ?>
                    <span class="contentlatch-finding__sep" aria-hidden="true">·</span>
                <?php endif; ?>
                <?php if ($canLink) : ?>
                    <a
                        class="contentlatch-finding__field"
                        href="<?php echo esc_url($issueEdit); ?>"
                        aria-label="<?php echo esc_attr(AuditPresentation::goToFieldEditAria($issueLabel)); ?>"
                    ><?php echo esc_html($issueLabel); ?></a>
                <?php else : ?>
                    <span class="contentlatch-finding__field"><?php echo esc_html($issueLabel); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($hiddenFields > 0) : ?>
                <span class="contentlatch-finding__sep" aria-hidden="true">·</span>
                <span class="contentlatch-finding__more"><?php echo esc_html(AuditPresentation::moreFieldsLabel($hiddenFields)); ?></span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php
    $affectedRows   = array();
    $nestedTokens   = AuditRepeaterCoordinates::tokensFromMessages($messages);
    $summaryDisplay = $summary;
    if ($nestedTokens !== array()) {
        if (AuditRepeaterCoordinates::cellsFromSnapshot($summaryDisplay) === array()) {
            $nestedSnapshot = AuditRepeaterCoordinates::firstSnapshotWithPairs($messages);
            if ($nestedSnapshot !== '') {
                $summaryDisplay = $nestedSnapshot;
            }
        }
    } else {
        foreach ($messages as $snapshot) {
            $parsed = EditorFieldNavigation::flexDisplayRowsFromSnapshot($snapshot);
            if (count($parsed) > 1) {
                $affectedRows = $parsed;
                if ($summary === $snapshot) {
                    $summaryDisplay = EditorFieldNavigation::snapshotMessageWithoutRows($summary);
                }
                break;
            }
        }
    }
    ?>
    <?php if ($summaryDisplay !== '') : ?>
        <p class="contentlatch-finding__issue">
            <?php echo esc_html($summaryDisplay); ?>
            <?php if ($nestedTokens !== array()) : ?>
                <span class="contentlatch-finding__rows">
                    <span class="screen-reader-text"><?php echo esc_html__('Affected rows:', 'contentlatch'); ?></span>
                    <?php foreach ($nestedTokens as $index => $token) : ?>
                        <?php if ($index > 0) : ?><span aria-hidden="true"> · </span><?php endif; ?>
                        <span><?php echo esc_html($token); ?></span>
                    <?php endforeach; ?>
                </span>
            <?php elseif ($affectedRows !== array()) : ?>
                <span class="contentlatch-finding__rows">
                    <span class="screen-reader-text"><?php echo esc_html__('Affected rows:', 'contentlatch'); ?></span>
                    <?php foreach ($affectedRows as $index => $row) : ?>
                        <?php if ($index > 0) : ?><span aria-hidden="true"> · </span><?php endif; ?>
                        <span><?php echo esc_html(sprintf(/* translators: %d: 1-based Flexible Content row number */ __('Row %d', 'contentlatch'), $row)); ?></span>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <p class="contentlatch-finding__footer">
        <?php if ($rule !== '') : ?>
            <span class="contentlatch-finding__rule">
                <?php echo esc_html(sprintf(/* translators: %s: rule name */ __('Rule: %s', 'contentlatch'), $rule)); ?>
            </span>
        <?php endif; ?>
        <?php if ($editUrl !== '') : ?>
            <a
                class="contentlatch-button--link contentlatch-finding__action"
                href="<?php echo esc_url($editUrl); ?>"
                aria-label="<?php echo esc_attr(AuditPresentation::editContentAria($title)); ?>"
            >
                <?php echo esc_html(AuditPresentation::editContentLabel()); ?>
            </a>
        <?php endif; ?>
    </p>
</article>
