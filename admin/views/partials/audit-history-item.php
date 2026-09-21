<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * One audit history entry.
 *
 * @package ContentGuard
 *
 * @var string $date
 * @var string $status
 * @var bool $isCurrent
 * @var bool $isViewing
 * @var string $checkedLabel
 * @var string $outcome
 * @var string $detail
 * @var string $actionLabel
 * @var string $actionUrl
 */

defined('ABSPATH') || exit;

use ContentGuard\Admin\AdminView;
use ContentGuard\Application\AuditPresentation;

$date         = isset($date) && is_string($date) ? $date : '';
$status       = isset($status) && is_string($status) ? $status : '';
$isCurrent    = !empty($isCurrent);
$isViewing    = !empty($isViewing);
$checkedLabel = isset($checkedLabel) && is_string($checkedLabel) ? $checkedLabel : '';
$outcome      = isset($outcome) && is_string($outcome) ? $outcome : '';
$detail       = isset($detail) && is_string($detail) ? $detail : '';
$actionLabel  = isset($actionLabel) && is_string($actionLabel) ? $actionLabel : '';
$actionUrl    = isset($actionUrl) && is_string($actionUrl) ? $actionUrl : '';

$classes = array('contentguard-history__item');
if ($isCurrent) {
    $classes[] = 'contentguard-history__item--current';
}
if ($isViewing) {
    $classes[] = 'contentguard-history__item--viewing';
}
if ($status === 'failed') {
    $classes[] = 'contentguard-history__item--failed';
}
if ($status === 'cancelled') {
    $classes[] = 'contentguard-history__item--cancelled';
}
?>
<article
    class="<?php echo esc_attr(implode(' ', $classes)); ?>"
    <?php echo $isViewing ? ' aria-current="true"' : ''; ?>
>
    <div class="contentguard-history__body">
        <header class="contentguard-history__header">
            <?php if ($date !== '') : ?>
                <h3 class="contentguard-history__date"><?php echo esc_html($date); ?></h3>
            <?php endif; ?>
            <p class="contentguard-history__flags">
                <?php if ($isCurrent) : ?>
                    <span class="contentguard-history__current"><?php echo esc_html(AuditPresentation::currentAuditLabel()); ?></span>
                <?php endif; ?>
                <?php AdminView::partial('status-pill', array('status' => $status)); ?>
                <?php if ($isViewing) : ?>
                    <span class="contentguard-history__viewing"><?php echo esc_html(AuditPresentation::viewingAuditLabel()); ?></span>
                <?php endif; ?>
            </p>
        </header>
        <?php if ($checkedLabel !== '') : ?>
            <p class="contentguard-history__stat"><?php echo esc_html($checkedLabel); ?></p>
        <?php endif; ?>
        <?php if ($outcome !== '') : ?>
            <p class="contentguard-history__stat"><?php echo esc_html($outcome); ?></p>
        <?php endif; ?>
        <?php if ($detail !== '') : ?>
            <p class="contentguard-history__detail"><?php echo esc_html($detail); ?></p>
        <?php endif; ?>
    </div>
    <?php if ($actionUrl !== '' && $actionLabel !== '') : ?>
        <p class="contentguard-history__action">
            <a href="<?php echo esc_url($actionUrl); ?>"><?php echo esc_html($actionLabel); ?></a>
        </p>
    <?php elseif ($actionLabel !== '') : ?>
        <p class="contentguard-history__action contentguard-history__action--static">
            <?php echo esc_html($actionLabel); ?>
        </p>
    <?php endif; ?>
</article>
