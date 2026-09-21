<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * Shared ContentGuard status pill.
 *
 * Status is never color-only: the label is always rendered as text.
 *
 * @package ContentGuard
 *
 * @var string $status
 * @var string $label
 */

defined('ABSPATH') || exit;

use ContentGuard\Application\StatusPresentation;

$status  = isset($status) && is_string($status) ? $status : '';
$label   = isset($label) && is_string($label) && $label !== '' ? $label : StatusPresentation::label($status);
$variant = StatusPresentation::variant($status);
?>
<span class="contentguard-status contentguard-status--<?php echo esc_attr($variant); ?>">
    <span class="contentguard-status__text"><?php echo esc_html($label); ?></span>
</span>
