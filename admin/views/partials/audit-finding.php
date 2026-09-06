<?php
/**
 * One audit finding (or grouped findings) as a compact content-first row.
 *
 * @package ContentGuard
 *
 * @var string $title
 * @var string $message
 * @var list<string> $messages
 * @var string $field
 * @var string $rule
 * @var string $status
 * @var list<string> $statuses
 * @var string $postType
 * @var string $editUrl
 * @var string $uid
 */

defined('ABSPATH') || exit;

use ContentGuard\Admin\AdminView;
use ContentGuard\Application\AuditPresentation;

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
$display    = AuditPresentation::postTitle($title);
$headingId  = 'contentguard-finding-title-' . $uid;
$primary    = $statuses[0] ?? 'blocking';
$modifier   = $primary === 'warning' ? 'warning' : 'blocking';
$multiple   = count($messages) > 1;
$countLabel = AuditPresentation::issuesCountLabel(count($messages));
?>
<article
    class="contentguard-finding contentguard-finding--<?php echo esc_attr($modifier); ?><?php echo $multiple ? ' contentguard-finding--grouped' : ''; ?>"
    aria-labelledby="<?php echo esc_attr($headingId); ?>"
>
    <div class="contentguard-finding__headline">
        <h3 class="contentguard-finding__title" id="<?php echo esc_attr($headingId); ?>"><?php echo esc_html($display); ?></h3>
        <?php if ($field !== '') : ?>
            <span class="contentguard-finding__sep" aria-hidden="true">·</span>
            <span class="contentguard-finding__field">
                <span class="screen-reader-text"><?php echo esc_html__('Field', 'contentguard'); ?>: </span>
                <?php echo esc_html($field); ?>
            </span>
        <?php endif; ?>
        <?php foreach ($statuses as $statusKey) : ?>
            <span class="contentguard-finding__sep" aria-hidden="true">·</span>
            <?php AdminView::partial('status-pill', array('status' => $statusKey)); ?>
        <?php endforeach; ?>
        <?php if ($multiple) : ?>
            <span class="contentguard-finding__sep" aria-hidden="true">·</span>
            <span class="contentguard-finding__count"><?php echo esc_html($countLabel); ?></span>
        <?php endif; ?>
        <?php if ($postType !== '') : ?>
            <span class="contentguard-finding__type"><?php echo esc_html($postType); ?></span>
        <?php endif; ?>
    </div>
    <?php if ($multiple) : ?>
        <ul class="contentguard-finding__issues">
            <?php foreach ($messages as $issue) : ?>
                <li><?php echo esc_html($issue); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php elseif (isset($messages[0])) : ?>
        <p class="contentguard-finding__issue"><?php echo esc_html($messages[0]); ?></p>
    <?php endif; ?>
    <p class="contentguard-finding__footer">
        <?php if ($rule !== '') : ?>
            <span class="contentguard-finding__rule">
                <?php echo esc_html(sprintf(/* translators: %s: rule name */ __('Rule: %s', 'contentguard'), $rule)); ?>
            </span>
        <?php endif; ?>
        <?php if ($editUrl !== '') : ?>
            <?php if ($rule !== '') : ?>
                <span class="contentguard-finding__sep" aria-hidden="true">·</span>
            <?php endif; ?>
            <a
                class="contentguard-button--link contentguard-finding__action"
                href="<?php echo esc_url($editUrl); ?>"
                aria-label="<?php echo esc_attr(AuditPresentation::editContentAria($title)); ?>"
            >
                <?php echo esc_html(AuditPresentation::editContentLabel()); ?>
            </a>
        <?php endif; ?>
    </p>
</article>
