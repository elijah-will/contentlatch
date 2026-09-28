<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * Shared ContentLatch status pill.
 *
 * Status is never color-only: the label is always rendered as text.
 *
 * @package ContentLatch
 *
 * @var string $status
 * @var string $label
 */

defined('ABSPATH') || exit;

use ContentLatch\Application\StatusPresentation;

$status  = isset($status) && is_string($status) ? $status : '';
$label   = isset($label) && is_string($label) && $label !== '' ? $label : StatusPresentation::label($status);
$variant = StatusPresentation::variant($status);
?>
<span class="contentlatch-status contentlatch-status--<?php echo esc_attr($variant); ?>">
    <span class="contentlatch-status__text"><?php echo esc_html($label); ?></span>
</span>
