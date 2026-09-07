<?php
/**
 * Discovers V1-supported ACF fields for a post type.
 *
 * Phase 10A catalogues supported scalar leaves inside Group fields.
 * Phase 10B also catalogues supported scalar leaves inside Repeater fields
 * (top-level Repeater → scalar, and Group → Repeater → scalar). Repeater
 * containers themselves are not selectable. Nested Repeaters, Flexible
 * Content, and Clone remain excluded.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Domain\FieldRef;

final class AcfFieldCatalog
{
    public const SUPPORTED_TYPES = array(
        'text',
        'textarea',
        'number',
        'range',
        'email',
        'url',
        'password',
        'wysiwyg',
        'select',
        'radio',
        'button_group',
        'true_false',
        'date_picker',
        'date_time_picker',
        'color_picker',
    );

    private const EXCLUDED_CONTAINERS = array(
        'flexible_content',
        'clone',
    );

    /**
     * @param callable(string $postType): array<int, array<string, mixed>>|null $source
     */
    public function __construct(private mixed $source = null)
    {
    }

    /**
     * @return FieldDefinition[]
     */
    public function fieldsForPostType(string $postType): array
    {
        $definitions = array();

        foreach ($this->loadRaw($postType) as $field) {
            foreach ($this->collect($field, array(), array(), array()) as $definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, string> Field key => ACF type.
     */
    public function fieldTypesForPostType(string $postType): array
    {
        $types = array();

        foreach ($this->fieldsForPostType($postType) as $field) {
            $types[$field->key] = $field->type;
        }

        return $types;
    }

    /**
     * Trusted nested-resolution maps for catalogued fields that are also
     * referenced by the current evaluation.
     *
     * @param array<string, string> $fieldTypes
     * @return array{
     *     paths: array<string, list<string>>,
     *     names: array<string, list<string>>,
     *     repeater_keys: array<string, string>
     * }
     */
    public function nestedResolutionMaps(string $postType, array $fieldTypes): array
    {
        $paths     = array();
        $names     = array();
        $repeaters = array();

        foreach ($this->fieldsForPostType($postType) as $field) {
            if (!isset($fieldTypes[$field->key]) || $field->path === array()) {
                continue;
            }

            $paths[$field->key] = $field->path;
            $names[$field->key] = $field->pathNames;
            if ($field->repeaterKey !== '') {
                $repeaters[$field->key] = $field->repeaterKey;
            }
        }

        return array(
            'paths'         => $paths,
            'names'         => $names,
            'repeater_keys' => $repeaters,
        );
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string>         $ancestorKeys
     * @param list<string>         $ancestorNames
     * @param list<string>         $ancestorLabels
     * @return list<FieldDefinition>
     */
    private function collect(
        array $field,
        array $ancestorKeys,
        array $ancestorNames,
        array $ancestorLabels,
        string $repeaterKey = '',
    ): array {
        $key  = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? '');

        if ($key === '' || !str_starts_with($key, 'field_')) {
            return array();
        }

        if ($type === 'group') {
            if ($repeaterKey !== '') {
                return array();
            }

            return $this->collectChildren(
                $field,
                $ancestorKeys,
                $ancestorNames,
                $ancestorLabels,
                $repeaterKey
            );
        }

        if ($type === FieldRef::CONTAINER_REPEATER) {
            if ($repeaterKey !== '') {
                return array();
            }

            return $this->collectChildren(
                $field,
                $ancestorKeys,
                $ancestorNames,
                $ancestorLabels,
                $key
            );
        }

        if (in_array($type, self::EXCLUDED_CONTAINERS, true)) {
            return array();
        }

        $definition = $this->mapField($field, $ancestorKeys, $ancestorNames, $ancestorLabels, $repeaterKey);

        return $definition !== null ? array($definition) : array();
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string>         $ancestorKeys
     * @param list<string>         $ancestorNames
     * @param list<string>         $ancestorLabels
     * @return list<FieldDefinition>
     */
    private function collectChildren(
        array $field,
        array $ancestorKeys,
        array $ancestorNames,
        array $ancestorLabels,
        string $repeaterKey,
    ): array {
        $name  = (string) ($field['name'] ?? '');
        $label = (string) ($field['label'] ?? '');
        $key   = (string) ($field['key'] ?? '');
        if ($label === '') {
            $label = $name !== '' ? $name : $key;
        }

        $nextKeys   = array_merge($ancestorKeys, array($key));
        $nextNames  = array_merge($ancestorNames, array($name));
        $nextLabels = array_merge($ancestorLabels, array($label));
        $collected  = array();

        foreach ($this->subFields($field) as $child) {
            foreach ($this->collect($child, $nextKeys, $nextNames, $nextLabels, $repeaterKey) as $definition) {
                $collected[] = $definition;
            }
        }

        return $collected;
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string>         $ancestorKeys
     * @param list<string>         $ancestorNames
     * @param list<string>         $ancestorLabels
     */
    private function mapField(
        array $field,
        array $ancestorKeys,
        array $ancestorNames,
        array $ancestorLabels,
        string $repeaterKey = '',
    ): ?FieldDefinition {
        $key  = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? '');

        if ($key === '' || !str_starts_with($key, 'field_')) {
            return null;
        }

        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return null;
        }

        if ($type === 'select' && !empty($field['multiple'])) {
            return null;
        }

        $name  = (string) ($field['name'] ?? '');
        $label = (string) ($field['label'] ?? '');

        $path       = array();
        $pathNames  = array();
        $pathLabels = array();
        $container  = '';

        if ($ancestorKeys !== array()) {
            $leafLabel  = $label !== '' ? $label : ($name !== '' ? $name : $key);
            $path       = array_merge($ancestorKeys, array($key));
            $pathNames  = array_merge($ancestorNames, array($name));
            $pathLabels = array_merge($ancestorLabels, array($leafLabel));
            $container  = $repeaterKey !== ''
                ? FieldRef::CONTAINER_REPEATER
                : FieldRef::CONTAINER_GROUP;
        }

        return new FieldDefinition(
            $key,
            $name,
            $label,
            $type,
            $this->choices($field),
            $path,
            $container,
            $pathNames,
            $pathLabels,
            $repeaterKey,
        );
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, string>
     */
    private function choices(array $field): array
    {
        $raw = $field['choices'] ?? null;
        if (!is_array($raw)) {
            return array();
        }

        $choices = array();
        foreach ($raw as $value => $label) {
            if (is_array($label) || is_object($label)) {
                continue;
            }

            $choices[(string) $value] = (string) $label;
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, array<string, mixed>>
     */
    private function subFields(array $field): array
    {
        $raw = $field['sub_fields'] ?? array();

        return is_array($raw) ? $this->onlyMaps($raw) : array();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRaw(string $postType): array
    {
        if (is_callable($this->source)) {
            $loaded = ($this->source)($postType);
            return is_array($loaded) ? $this->onlyMaps($loaded) : array();
        }

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return array();
        }

        $groups = acf_get_field_groups(array('post_type' => $postType));
        if (!is_array($groups)) {
            return array();
        }

        $fields = array();

        foreach ($groups as $group) {
            $groupFields = acf_get_fields($group);
            if (!is_array($groupFields)) {
                continue;
            }

            foreach ($this->onlyMaps($groupFields) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function onlyMaps(array $items): array
    {
        $maps = array();

        foreach ($items as $item) {
            if (is_array($item)) {
                $maps[] = $item;
            }
        }

        return $maps;
    }
}
