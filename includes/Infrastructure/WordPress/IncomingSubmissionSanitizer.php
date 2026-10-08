<?php
/**
 * Sanitizes submitted Core/ACF evaluation payloads at the request boundary.
 *
 * Values are for IncomingSaveEvaluator only; ContentLatch does not persist them.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class IncomingSubmissionSanitizer
{
    /**
     * Build a field-key → type map for sanitizing posted ACF trees without
     * per-field database reads. Includes resolution ids, raw keys, and path leaves.
     *
     * @param list<\ContentLatch\Infrastructure\ACF\FieldDefinition> $fields
     * @return array<string, string>
     */
    public static function acfFieldTypes(array $fields): array
    {
        $types = array();
        foreach ($fields as $field) {
            if (!is_object($field) || !isset($field->type, $field->key)) {
                continue;
            }
            $type = (string) $field->type;
            $types[(string) $field->key] = $type;
            if (is_callable(array($field, 'resolutionId'))) {
                $types[(string) $field->resolutionId()] = $type;
            }
            if (isset($field->path) && is_array($field->path) && $field->path !== array()) {
                $leaf = $field->path[array_key_last($field->path)];
                if (is_string($leaf) && $leaf !== '') {
                    $types[$leaf] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * Recursively sanitize an ACF $_POST['acf'] tree.
     *
     * @param array<string, string> $fieldTypes resolution id / field key => ACF type
     * @return array<int|string, mixed>
     */
    public static function acfTree(mixed $tree, array $fieldTypes = array()): array
    {
        if (!is_array($tree)) {
            return array();
        }

        return self::walkAcf($tree, $fieldTypes);
    }

    /**
     * Sanitize one Core evaluation field from an unslashed request value.
     */
    public static function coreField(string $key, mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return match ($key) {
                'featured_image', '_thumbnail_id', 'thumbnail_id', 'featured_media',
                'author', 'post_author' => 0,
                default => '',
            };
        }

        return match ($key) {
            'content', 'post_content' => wp_kses_post((string) $value),
            'excerpt', 'post_excerpt' => sanitize_textarea_field((string) $value),
            'slug', 'post_name' => sanitize_title((string) $value),
            'featured_image', '_thumbnail_id', 'thumbnail_id', 'featured_media' => self::sanitizeAttachmentId($value),
            'author', 'post_author' => absint($value),
            'title', 'post_title' => sanitize_text_field((string) $value),
            default => sanitize_text_field((string) $value),
        };
    }

    /**
     * @param array<int|string, mixed> $tree
     * @param array<string, string>    $fieldTypes
     * @return array<int|string, mixed>
     */
    private static function walkAcf(array $tree, array $fieldTypes): array
    {
        $clean = array();

        foreach ($tree as $key => $value) {
            if (is_object($value)) {
                continue;
            }

            if (is_array($value)) {
                // Preserve repeater indexes and nested field maps.
                $clean[$key] = self::walkAcf($value, $fieldTypes);
                continue;
            }

            $type = is_string($key) && isset($fieldTypes[$key])
                ? $fieldTypes[$key]
                : '';
            $clean[$key] = self::acfScalar($type, $value);
        }

        return $clean;
    }

    private static function acfScalar(string $type, mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            if ($type === 'true_false' || $type === 'number' || $type === 'range' || $type === '') {
                return $value;
            }

            return sanitize_text_field((string) $value);
        }

        if (!is_scalar($value)) {
            return '';
        }

        $string = (string) $value;

        return match ($type) {
            'textarea' => sanitize_textarea_field($string),
            'wysiwyg' => wp_kses_post($string),
            'email' => sanitize_email($string),
            'url' => esc_url_raw($string),
            'number', 'range' => self::preserveNumericString($string),
            'true_false' => self::preserveTrueFalseString($string),
            'select', 'radio', 'button_group',
            'text', 'password', 'color_picker',
            'date_picker', 'date_time_picker' => sanitize_text_field($string),
            default => wp_kses_post($string),
        };
    }

    private static function preserveNumericString(string $value): string
    {
        if ($value !== trim($value) || $value === '' || !is_numeric($value) || str_contains($value, ',')) {
            return sanitize_text_field($value);
        }

        return $value;
    }

    private static function preserveTrueFalseString(string $value): string
    {
        if ($value === '0' || $value === '1') {
            return $value;
        }

        return sanitize_text_field($value);
    }

    /**
     * Classic editor posts `_thumbnail_id=-1` when no featured image is selected.
     * Preserve that sentinel so CoreValueNormalizer still treats it as absent;
     * absint(-1) would become 1 and flip required checks to pass.
     */
    private static function sanitizeAttachmentId(mixed $value): int
    {
        if ($value === -1 || $value === '-1') {
            return -1;
        }

        return absint($value);
    }
}
