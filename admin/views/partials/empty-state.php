<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * Shared ContentGuard empty state.
 *
 * @package ContentGuard
 *
 * @var string $heading
 * @var string $text
 * @var string $note
 * @var array{label: string, href?: string, class?: string, attrs?: array<string, string>, disabled?: bool}|null $primary
 * @var array{label: string, href?: string, class?: string, attrs?: array<string, string>, disabled?: bool}|null $secondary
 */

defined('ABSPATH') || exit;

$heading   = isset($heading) && is_string($heading) ? $heading : '';
$text      = isset($text) && is_string($text) ? $text : '';
$note      = isset($note) && is_string($note) ? $note : '';
$primary   = isset($primary) && is_array($primary) ? $primary : null;
$secondary = isset($secondary) && is_array($secondary) ? $secondary : null;

$renderAction = static function (array $action, string $defaultClass): void {
    $label    = (string) ($action['label'] ?? '');
    $href     = isset($action['href']) ? (string) $action['href'] : '';
    $class    = trim($defaultClass . ' ' . (string) ($action['class'] ?? ''));
    $attrs    = isset($action['attrs']) && is_array($action['attrs']) ? $action['attrs'] : array();
    $disabled = !empty($action['disabled']);

    $attrHtml = '';
    foreach ($attrs as $name => $value) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        $attrHtml .= ' ' . esc_attr($name) . '="' . esc_attr((string) $value) . '"';
    }

    if ($href !== '') {
        echo '<a href="' . esc_url($href) . '" class="' . esc_attr($class) . '"' . $attrHtml . '>'
            . esc_html($label)
            . '</a>';
        return;
    }

    echo '<button type="button" class="' . esc_attr($class) . '"' . $attrHtml;
    disabled($disabled);
    echo '>' . esc_html($label) . '</button>';
};
?>
<div class="contentguard-empty">
    <?php if ($heading !== '') : ?>
        <h2 class="contentguard-empty__heading"><?php echo esc_html($heading); ?></h2>
    <?php endif; ?>
    <?php if ($text !== '') : ?>
        <p class="contentguard-empty__text"><?php echo esc_html($text); ?></p>
    <?php endif; ?>
    <?php if ($note !== '') : ?>
        <p class="contentguard-empty__text"><?php echo esc_html($note); ?></p>
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
