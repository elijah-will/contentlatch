<?php
/**
 * Shared ContentGuard empty state.
 *
 * Used by the Rules list when no rules exist.
 *
 * @package ContentGuard
 *
 * @var string $heading
 * @var string $text
 * @var array{label: string, href?: string, class?: string}|null $primary
 * @var array{label: string, href?: string, class?: string}|null $secondary
 */

defined('ABSPATH') || exit;

$heading   = isset($heading) && is_string($heading) ? $heading : '';
$text      = isset($text) && is_string($text) ? $text : '';
$primary   = isset($primary) && is_array($primary) ? $primary : null;
$secondary = isset($secondary) && is_array($secondary) ? $secondary : null;

$renderAction = static function (array $action, string $defaultClass): void {
    $label = (string) ($action['label'] ?? '');
    $href  = isset($action['href']) ? (string) $action['href'] : '';
    $class = trim($defaultClass . ' ' . (string) ($action['class'] ?? ''));

    if ($href !== '') {
        echo '<a href="' . esc_url($href) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        return;
    }

    echo '<button type="button" class="' . esc_attr($class) . '">' . esc_html($label) . '</button>';
};
?>
<div class="contentguard-empty">
    <?php if ($heading !== '') : ?>
        <h2 class="contentguard-empty__heading"><?php echo esc_html($heading); ?></h2>
    <?php endif; ?>
    <?php if ($text !== '') : ?>
        <p class="contentguard-empty__text"><?php echo esc_html($text); ?></p>
    <?php endif; ?>
    <?php if ($primary !== null || $secondary !== null) : ?>
        <div class="contentguard-empty__actions">
            <?php
            if ($primary !== null) {
                $renderAction($primary, 'button button-primary');
            }
            if ($secondary !== null) {
                $renderAction($secondary, 'button');
            }
            ?>
        </div>
    <?php endif; ?>
</div>
