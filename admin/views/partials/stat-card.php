<?php
/**
 * Shared ContentGuard stat card.
 *
 * Established for later Audit use. Not required on current screens.
 *
 * @package ContentGuard
 *
 * @var string $label
 * @var string|int $value
 * @var string $helper
 * @var string $status
 * @var string $variant
 */

defined('ABSPATH') || exit;

use ContentGuard\Admin\AdminView;

$label   = isset($label) && is_string($label) ? $label : '';
$value   = isset($value) && (is_string($value) || is_int($value) || is_float($value)) ? (string) $value : '';
$helper  = isset($helper) && is_string($helper) ? $helper : '';
$status  = isset($status) && is_string($status) ? $status : '';
$variant = isset($variant) && is_string($variant) && $variant !== '' ? $variant : '';
$class   = 'contentguard-stat-card' . ($variant !== '' ? ' contentguard-stat-card--' . $variant : '');
?>
<div class="<?php echo esc_attr($class); ?>">
    <span class="contentguard-stat-card__label"><?php echo esc_html($label); ?></span>
    <span class="contentguard-stat-card__value"><?php echo esc_html($value); ?></span>
    <?php if ($helper !== '') : ?>
        <span class="contentguard-stat-card__helper"><?php echo esc_html($helper); ?></span>
    <?php endif; ?>
    <?php if ($status !== '') : ?>
        <span class="contentguard-stat-card__status">
            <?php AdminView::partial('status-pill', array('status' => $status)); ?>
        </span>
    <?php endif; ?>
</div>
