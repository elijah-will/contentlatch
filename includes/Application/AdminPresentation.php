<?php
/**
 * Shared admin presentation helpers.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

final class AdminPresentation
{
    /**
     * @param array<string, string> $labels Slug => human-readable name.
     */
    public static function postTypeLabel(string $slug, array $labels = array()): string
    {
        $label = $labels[$slug] ?? '';
        if (is_string($label) && $label !== '') {
            return $label;
        }

        return self::resolvePostTypeLabel($slug);
    }

    public static function resolvePostTypeLabel(string $slug): string
    {
        if (function_exists('get_post_type_object')) {
            $object = get_post_type_object($slug);
            if (is_object($object) && isset($object->labels) && is_object($object->labels)) {
                $name = (string) ($object->labels->singular_name ?? '');
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $slug;
    }
}
