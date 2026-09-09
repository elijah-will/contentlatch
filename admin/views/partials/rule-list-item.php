<?php
/**
 * One stacked Rules list item.
 *
 * @package ContentGuard
 *
 * @var \ContentGuard\Domain\Rule $rule
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

use ContentGuard\Admin\AdminView;
use ContentGuard\Application\StatusPresentation;
use ContentGuard\Domain\RuleStatus;

$rule          = $rule ?? null;
$summary       = isset($summary) && is_array($summary) ? $summary : array('conditions' => '', 'validations' => '');
$postTypeLabel = isset($postTypeLabel) && is_string($postTypeLabel) ? $postTypeLabel : '';
$impactLabel   = isset($impactLabel) && is_string($impactLabel) ? $impactLabel : '';
$editUrl       = isset($editUrl) && is_string($editUrl) ? $editUrl : '';
$statusUrl     = isset($statusUrl) && is_string($statusUrl) ? $statusUrl : '';
$deleteUrl     = isset($deleteUrl) && is_string($deleteUrl) ? $deleteUrl : '';
$affectedUrl   = isset($affectedUrl) && is_string($affectedUrl) ? $affectedUrl : '';
$showAffected  = !empty($showAffected);

if (!$rule instanceof \ContentGuard\Domain\Rule) {
    return;
}

$ruleId        = (int) $rule->id;
$titleId       = 'contentguard-rule-title-' . $ruleId;
$isActive      = $rule->status === RuleStatus::Active;
$statusLabel   = $isActive ? __('Deactivate', 'contentguard') : __('Activate', 'contentguard');
$deleteConfirm = __('Delete this rule? Audit findings for this rule will be kept.', 'contentguard');
?>
<article class="contentguard-panel contentguard-rule-item" aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <header class="contentguard-rule-item__header">
        <h3 class="contentguard-rule-item__title" id="<?php echo esc_attr($titleId); ?>">
            <a href="<?php echo esc_url($editUrl); ?>"><?php echo esc_html($rule->name); ?></a>
        </h3>
        <div class="contentguard-rule-item__status">
            <?php AdminView::partial('status-pill', array('status' => StatusPresentation::fromRuleStatus($rule->status))); ?>
            <?php AdminView::partial('status-pill', array('status' => StatusPresentation::fromSeverity($rule->severity))); ?>
        </div>
    </header>

    <p class="contentguard-rule-item__applies">
        <?php echo esc_html(sprintf(/* translators: %s: post type label */ __('Applies to: %s', 'contentguard'), $postTypeLabel)); ?>
    </p>

    <dl class="contentguard-rule-item__logic">
        <div>
            <dt><?php echo esc_html__('WHEN', 'contentguard'); ?></dt>
            <dd><?php echo esc_html((string) ($summary['conditions'] ?? '')); ?></dd>
        </div>
        <div>
            <dt><?php echo esc_html__('THEN', 'contentguard'); ?></dt>
            <dd><?php echo esc_html((string) ($summary['validations'] ?? '')); ?></dd>
        </div>
    </dl>

    <p class="contentguard-rule-item__impact">
        <?php echo esc_html($impactLabel); ?>
        <?php if ($showAffected) : ?>
            <span aria-hidden="true"> · </span>
            <a
                href="<?php echo esc_url($affectedUrl); ?>"
                aria-label="<?php echo esc_attr(sprintf(/* translators: %s: rule name */ __('View affected content: %s', 'contentguard'), $rule->name)); ?>"
            >
                <?php echo esc_html__('View affected content', 'contentguard'); ?>
            </a>
        <?php endif; ?>
    </p>

    <div class="contentguard-rule-item__actions">
        <a
            class="contentguard-button--link"
            href="<?php echo esc_url($editUrl); ?>"
            aria-label="<?php echo esc_attr(sprintf(/* translators: %s: rule name */ __('Edit: %s', 'contentguard'), $rule->name)); ?>"
        >
            <?php echo esc_html__('Edit', 'contentguard'); ?>
        </a>
        <span class="contentguard-rule-item__action-sep" aria-hidden="true">·</span>
        <a
            class="contentguard-button--link"
            href="<?php echo esc_url($statusUrl); ?>"
            aria-label="<?php echo esc_attr($isActive
                ? sprintf(/* translators: %s: rule name */ __('Deactivate: %s', 'contentguard'), $rule->name)
                : sprintf(/* translators: %s: rule name */ __('Activate: %s', 'contentguard'), $rule->name)); ?>"
        >
            <?php echo esc_html($statusLabel); ?>
        </a>
        <span class="contentguard-rule-item__action-sep" aria-hidden="true">·</span>
        <a
            class="submitdelete contentguard-button--destructive"
            href="<?php echo esc_url($deleteUrl); ?>"
            aria-label="<?php echo esc_attr(sprintf(/* translators: %s: rule name */ __('Delete: %s', 'contentguard'), $rule->name)); ?>"
            onclick="return confirm('<?php echo esc_js($deleteConfirm); ?>');"
        >
            <?php echo esc_html__('Delete', 'contentguard'); ?>
        </a>
    </div>
</article>
