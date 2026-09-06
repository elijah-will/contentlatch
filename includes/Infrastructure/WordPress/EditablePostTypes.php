<?php
/**
 * WordPress post types that can receive ContentGuard rules.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

final class EditablePostTypes
{
    /**
     * @return array<string, string> slug => label
     */
    public static function choices(): array
    {
        if (!function_exists('get_post_types')) {
            return array();
        }

        $types = get_post_types(array('show_ui' => true), 'objects');
        if (!is_array($types)) {
            return array();
        }

        $choices = array();
        foreach ($types as $type) {
            if (!$type instanceof \WP_Post_Type) {
                continue;
            }

            if (in_array($type->name, array('attachment', RulePostType::POST_TYPE), true)) {
                continue;
            }

            $label = (string) $type->labels->singular_name;
            if ($label === '') {
                $label = (string) $type->label;
            }

            $choices[$type->name] = $label !== '' ? $label : $type->name;
        }

        return $choices;
    }
}
