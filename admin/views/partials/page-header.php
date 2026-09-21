<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * Shared ContentGuard page header.
 *
 * @package ContentGuard
 *
 * @var string $title
 * @var string $description
 * @var string $eyebrow
 * @var array{label: string, href?: string, class?: string, attrs?: array<string, string>, disabled?: bool}|null $primary
 * @var array<int, array{label: string, href?: string, class?: string, attrs?: array<string, string>, disabled?: bool}> $secondary
 */

defined('ABSPATH') || exit;

$eyebrow     = isset($eyebrow) && is_string($eyebrow) && $eyebrow !== '' ? $eyebrow : __('ContentGuard', 'contentguard');
$title       = isset($title) && is_string($title) ? $title : '';
$description = isset($description) && is_string($description) ? $description : '';
$primary     = isset($primary) && is_array($primary) ? $primary : null;
$secondary   = isset($secondary) && is_array($secondary) ? $secondary : array();

$renderAction = static function (array $action, string $defaultClass): void {
    $label   = (string) ($action['label'] ?? '');
    $href    = isset($action['href']) ? (string) $action['href'] : '';
    $class   = trim($defaultClass . ' ' . (string) ($action['class'] ?? ''));
    $attrs   = isset($action['attrs']) && is_array($action['attrs']) ? $action['attrs'] : array();
    $disabled = !empty($action['disabled']);

    $attrHtml = '';
    foreach ($attrs as $name => $value) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        $attrHtml .= ' ' . esc_attr($name) . '="' . esc_attr((string) $value) . '"';
    }

    if ($href !== '') {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute names/values in $attrHtml are individually escaped with esc_attr before concatenation.
        echo '<a href="' . esc_url($href) . '" class="' . esc_attr($class) . '"' . $attrHtml . '>'
            . esc_html($label)
            . '</a>';
        return;
    }

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute names/values in $attrHtml are individually escaped with esc_attr before concatenation.
    echo '<button type="button" class="' . esc_attr($class) . '"' . $attrHtml;
    disabled($disabled);
    echo '>' . esc_html($label) . '</button>';
};
?>
<header class="contentguard-page-header">
    <div class="contentguard-page-header__text">
        <p class="contentguard-page-header__eyebrow"><?php echo esc_html($eyebrow); ?></p>
        <h1 class="contentguard-page-header__title"><?php echo esc_html($title); ?></h1>
        <?php if ($description !== '') : ?>
            <p class="contentguard-page-header__description"><?php echo esc_html($description); ?></p>
        <?php endif; ?>
    </div>
    <?php if ($primary !== null || $secondary !== array()) : ?>
        <div class="contentguard-page-header__actions">
            <?php
            if ($primary !== null) {
                $renderAction($primary, 'button button-primary');
            }
            foreach ($secondary as $action) {
                if (is_array($action)) {
                    $renderAction($action, 'button');
                }
            }
            ?>
        </div>
    <?php endif; ?>
</header>
<hr class="wp-header-end">
