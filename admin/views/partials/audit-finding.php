<?php
/**
 * One audit finding as a content-first card.
 *
 * @package ContentGuard
 *
 * @var string $title
 * @var string $message
 * @var string $field
 * @var string $rule
 * @var string $status
 * @var string $postType
 * @var string $editUrl
 * @var string $uid
 */

defined('ABSPATH') || exit;

use ContentGuard\Admin\AdminView;
use ContentGuard\Application\AuditPresentation;

$title    = isset($title) && is_string($title) ? $title : '';
$message  = isset($message) && is_string($message) ? $message : '';
$field    = isset($field) && is_string($field) ? $field : '';
$rule     = isset($rule) && is_string($rule) ? $rule : '';
$status   = isset($status) && is_string($status) ? $status : '';
$postType = isset($postType) && is_string($postType) ? $postType : '';
$editUrl  = isset($editUrl) && is_string($editUrl) ? $editUrl : '';
$uid      = isset($uid) && is_string($uid) && $uid !== '' ? $uid : 'item';
$display  = AuditPresentation::postTitle($title);
$headingId = 'contentguard-finding-title-' . $uid;
$modifier = $status === 'warning' ? 'warning' : 'blocking';
?>
<article
    class="contentguard-panel contentguard-finding contentguard-finding--<?php echo esc_attr($modifier); ?>"
    aria-labelledby="<?php echo esc_attr($headingId); ?>"
>
    <h3 class="contentguard-finding__title" id="<?php echo esc_attr($headingId); ?>"><?php echo esc_html($display); ?></h3>
    <?php if ($message !== '') : ?>
        <p class="contentguard-finding__issue"><?php echo esc_html($message); ?></p>
    <?php endif; ?>
    <div class="contentguard-finding__context">
        <?php if ($field !== '') : ?>
            <p class="contentguard-finding__field">
                <span class="screen-reader-text"><?php echo esc_html__('Field', 'contentguard'); ?>: </span>
                <?php echo esc_html($field); ?>
            </p>
        <?php endif; ?>
        <div class="contentguard-finding__meta">
            <?php AdminView::partial('status-pill', array('status' => $status)); ?>
            <?php if ($rule !== '') : ?>
                <span class="contentguard-finding__rule">
                    <?php echo esc_html(sprintf(/* translators: %s: rule name */ __('Rule: %s', 'contentguard'), $rule)); ?>
                </span>
            <?php endif; ?>
            <?php if ($postType !== '') : ?>
                <span class="contentguard-finding__type"><?php echo esc_html($postType); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($editUrl !== '') : ?>
        <p class="contentguard-finding__action">
            <a
                class="contentguard-button--link"
                href="<?php echo esc_url($editUrl); ?>"
                aria-label="<?php echo esc_attr(AuditPresentation::editContentAria($title)); ?>"
            >
                <?php echo esc_html(AuditPresentation::editContentLabel()); ?>
            </a>
        </p>
    <?php endif; ?>
</article>
