<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * One stacked Rules list item.
 *
 * @package ContentLatch
 *
 * @var \ContentLatch\Domain\Rule $rule
 * @var array{conditions: string, validations: string} $summary
 * @var string $postTypeLabel
 * @var string $impactLabel
 * @var string $editUrl
 * @var string $statusUrl
 * @var string $deleteUrl
 * @var string $affectedUrl
 * @var bool $showAffected
 */

defined('ABSPATH') || exit;

use ContentLatch\Admin\AdminView;
use ContentLatch\Application\StatusPresentation;
use ContentLatch\Domain\RuleStatus;

$rule          = $rule ?? null;
$summary       = isset($summary) && is_array($summary) ? $summary : array('conditions' => '', 'validations' => '');
$postTypeLabel = isset($postTypeLabel) && is_string($postTypeLabel) ? $postTypeLabel : '';
$impactLabel   = isset($impactLabel) && is_string($impactLabel) ? $impactLabel : '';
$editUrl       = isset($editUrl) && is_string($editUrl) ? $editUrl : '';
$statusUrl     = isset($statusUrl) && is_string($statusUrl) ? $statusUrl : '';
$deleteUrl     = isset($deleteUrl) && is_string($deleteUrl) ? $deleteUrl : '';
$affectedUrl   = isset($affectedUrl) && is_string($affectedUrl) ? $affectedUrl : '';
$showAffected  = !empty($showAffected);

if (!$rule instanceof \ContentLatch\Domain\Rule) {
    return;
}

$ruleId        = (int) $rule->id;
$titleId       = 'contentlatch-rule-title-' . $ruleId;
$isActive      = $rule->status === RuleStatus::Active;
$statusLabel   = $isActive ? __('Deactivate', 'contentlatch') : __('Activate', 'contentlatch');
?>
<article class="contentlatch-panel contentlatch-rule-item" aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <header class="contentlatch-rule-item__header">
        <h3 class="contentlatch-rule-item__title" id="<?php echo esc_attr($titleId); ?>">
            <a href="<?php echo esc_url($editUrl); ?>"><?php echo esc_html($rule->name); ?></a>
        </h3>
        <div class="contentlatch-rule-item__status">
            <?php AdminView::partial('status-pill', array('status' => StatusPresentation::fromRuleStatus($rule->status))); ?>
            <?php AdminView::partial('status-pill', array('status' => StatusPresentation::fromSeverity($rule->severity))); ?>
        </div>
    </header>

    <p class="contentlatch-rule-item__applies">
        <?php echo esc_html(sprintf(/* translators: %s: post type label */ __('Applies to: %s', 'contentlatch'), $postTypeLabel)); ?>
    </p>

    <dl class="contentlatch-rule-item__logic">
        <div>
            <dt><?php echo esc_html__('WHEN', 'contentlatch'); ?></dt>
            <dd><?php echo esc_html((string) ($summary['conditions'] ?? '')); ?></dd>
        </div>
        <div>
            <dt><?php echo esc_html__('THEN', 'contentlatch'); ?></dt>
            <dd><?php echo esc_html((string) ($summary['validations'] ?? '')); ?></dd>
        </div>
    </dl>

    <p class="contentlatch-rule-item__impact">
        <?php echo esc_html($impactLabel); ?>
        <?php if ($showAffected) : ?>
            <span aria-hidden="true"> · </span>
            <a
                href="<?php echo esc_url($affectedUrl); ?>"
                aria-label="<?php echo esc_attr(sprintf(/* translators: %s: rule name */ __('View affected content: %s', 'contentlatch'), $rule->name)); ?>"
            >
                <?php echo esc_html__('View affected content', 'contentlatch'); ?>
            </a>
        <?php endif; ?>
    </p>

    <div class="contentlatch-rule-item__actions">
        <a
            class="contentlatch-button--link"
            href="<?php echo esc_url($editUrl); ?>"
            aria-label="<?php echo esc_attr(sprintf(/* translators: %s: rule name */ __('Edit: %s', 'contentlatch'), $rule->name)); ?>"
        >
            <?php echo esc_html__('Edit', 'contentlatch'); ?>
        </a>
        <span class="contentlatch-rule-item__action-sep" aria-hidden="true">·</span>
        <a
            class="contentlatch-button--link"
            href="<?php echo esc_url($statusUrl); ?>"
            aria-label="<?php echo esc_attr($isActive
                ? sprintf(/* translators: %s: rule name */ __('Deactivate: %s', 'contentlatch'), $rule->name)
                : sprintf(/* translators: %s: rule name */ __('Activate: %s', 'contentlatch'), $rule->name)); ?>"
        >
            <?php echo esc_html($statusLabel); ?>
        </a>
        <span class="contentlatch-rule-item__action-sep" aria-hidden="true">·</span>
        <a
            class="submitdelete contentlatch-button--destructive contentlatch-delete-rule"
            href="<?php echo esc_url($deleteUrl); ?>"
            aria-label="<?php echo esc_attr(sprintf(/* translators: %s: rule name */ __('Delete: %s', 'contentlatch'), $rule->name)); ?>"
        >
            <?php echo esc_html__('Delete', 'contentlatch'); ?>
        </a>
    </div>
</article>
