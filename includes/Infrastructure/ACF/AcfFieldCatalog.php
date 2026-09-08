<?php
/**
 * Discovers V1-supported ACF fields for a post type.
 *
 * Phase 10A catalogues supported scalar leaves inside Group fields.
 * Phase 10B also catalogues supported scalar leaves inside Repeater fields
 * (top-level Repeater → scalar, and Group → Repeater → scalar).
 * Phase 10C catalogues supported scalar leaves inside Flexible Content
 * layouts (top-level Flex → scalar, and Flex → Group → scalar).
 * Phase 10D catalogues supported scalar leaves inside Clone fields
 * (Seamless and Group display, Clone → Group, and Clone inside
 * Repeater/Flexible). Clone → Clone/Repeater/Flexible remain excluded.
 * Containers themselves are not selectable. Nested Repeaters, Repeater
 * inside Flex, nested Flex, and Flex inside Repeater/Group remain
 * excluded.
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

    private const EXCLUDED_CONTAINERS = array();

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
            $types[$field->resolutionId()] = $field->type;
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
     *     repeater_keys: array<string, string>,
     *     flex_keys: array<string, string>,
     *     layouts: array<string, string>,
     *     clone_keys: array<string, string>
     * }
     */
    public function nestedResolutionMaps(string $postType, array $fieldTypes): array
    {
        $paths     = array();
        $names     = array();
        $repeaters = array();
        $flexKeys  = array();
        $layouts   = array();
        $cloneKeys = array();

        foreach ($this->fieldsForPostType($postType) as $field) {
            $id = $field->resolutionId();
            if (!isset($fieldTypes[$id]) || $field->path === array()) {
                continue;
            }

            $paths[$id] = $field->path;
            $names[$id] = $field->pathNames;
            if ($field->repeaterKey !== '') {
                $repeaters[$id] = $field->repeaterKey;
            }
            if ($field->container === FieldRef::CONTAINER_FLEXIBLE && $field->layout !== '') {
                $flexKeys[$id] = $field->path[0];
                $layouts[$id]  = $field->layout;
            }
            if ($field->clone !== '') {
                $cloneKeys[$id] = $field->clone;
            }
        }

        return array(
            'paths'         => $paths,
            'names'         => $names,
            'repeater_keys' => $repeaters,
            'flex_keys'     => $flexKeys,
            'layouts'       => $layouts,
            'clone_keys'    => $cloneKeys,
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
        string $flexKey = '',
        string $layout = '',
        string $layoutKey = '',
        string $layoutLabel = '',
        string $cloneKey = '',
        string $cloneLabel = '',
        string $cloneDisplay = '',
    ): array {
        $key  = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? '');

        if ($key === '' || !str_starts_with($key, 'field_')) {
            return array();
        }

        if ($type === FieldRef::CONTAINER_CLONE) {
            if ($cloneKey !== '') {
                return array();
            }

            return $this->collectClone(
                $field,
                $ancestorKeys,
                $ancestorNames,
                $ancestorLabels,
                $repeaterKey,
                $flexKey,
                $layout,
                $layoutKey,
                $layoutLabel
            );
        }

        if ($cloneKey === '' && $this->clonedFrom($field) !== '') {
            return array();
        }

        if ($type === FieldRef::CONTAINER_FLEXIBLE) {
            if ($repeaterKey !== '' || $flexKey !== '' || $cloneKey !== '' || $ancestorKeys !== array()) {
                return array();
            }

            return $this->collectFlexibleLayouts($field);
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
                $repeaterKey,
                $flexKey,
                $layout,
                $layoutKey,
                $layoutLabel,
                $cloneKey,
                $cloneLabel,
                $cloneDisplay
            );
        }

        if ($type === FieldRef::CONTAINER_REPEATER) {
            if ($repeaterKey !== '' || $flexKey !== '' || $cloneKey !== '') {
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

        $definition = $this->mapField(
            $field,
            $ancestorKeys,
            $ancestorNames,
            $ancestorLabels,
            $repeaterKey,
            $flexKey,
            $layout,
            $layoutKey,
            $layoutLabel,
            $cloneKey,
            $cloneLabel,
            $cloneDisplay
        );

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
        string $flexKey = '',
        string $layout = '',
        string $layoutKey = '',
        string $layoutLabel = '',
        string $cloneKey = '',
        string $cloneLabel = '',
        string $cloneDisplay = '',
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
            foreach ($this->collect(
                $child,
                $nextKeys,
                $nextNames,
                $nextLabels,
                $repeaterKey,
                $flexKey,
                $layout,
                $layoutKey,
                $layoutLabel,
                $cloneKey,
                $cloneLabel,
                $cloneDisplay
            ) as $definition) {
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
     * @return list<FieldDefinition>
     */
    private function collectClone(
        array $field,
        array $ancestorKeys,
        array $ancestorNames,
        array $ancestorLabels,
        string $repeaterKey,
        string $flexKey,
        string $layout,
        string $layoutKey,
        string $layoutLabel
    ): array {
        $key = (string) ($field['key'] ?? '');
        if ($key === '' || !str_starts_with($key, 'field_')) {
            return array();
        }

        $name    = (string) ($field['name'] ?? '');
        $label   = (string) ($field['label'] ?? '');
        $display = (string) ($field['display'] ?? 'seamless');
        if ($display !== 'group') {
            $display = 'seamless';
        }
        if ($label === '') {
            $label = $name !== '' ? $name : $key;
        }

        $nextKeys   = array_merge($ancestorKeys, array($key));
        $nextNames  = array_merge($ancestorNames, array($name));
        $nextLabels = array_merge($ancestorLabels, array($label));
        $collected  = array();

        foreach ($this->subFields($field) as $child) {
            $childType = (string) ($child['type'] ?? '');
            if (
                $childType === FieldRef::CONTAINER_CLONE
                || $childType === FieldRef::CONTAINER_REPEATER
                || $childType === FieldRef::CONTAINER_FLEXIBLE
            ) {
                continue;
            }

            foreach ($this->collect(
                $child,
                $nextKeys,
                $nextNames,
                $nextLabels,
                $repeaterKey,
                $flexKey,
                $layout,
                $layoutKey,
                $layoutLabel,
                $key,
                $label,
                $display
            ) as $definition) {
                $collected[] = $definition;
            }
        }

        return $collected;
    }

    /**
     * @param array<string, mixed> $field
     * @return list<FieldDefinition>
     */
    private function collectFlexibleLayouts(array $field): array
    {
        $key  = (string) ($field['key'] ?? '');
        $name = (string) ($field['name'] ?? '');
        $label = (string) ($field['label'] ?? '');
        if ($label === '') {
            $label = $name !== '' ? $name : $key;
        }

        $collected = array();
        $layouts   = $field['layouts'] ?? array();
        if (!is_array($layouts)) {
            return array();
        }

        foreach ($layouts as $layout) {
            if (!is_array($layout)) {
                continue;
            }

            $layoutName  = (string) ($layout['name'] ?? '');
            if (!FieldRef::isSafeLayoutName($layoutName)) {
                continue;
            }

            $layoutKey   = (string) ($layout['key'] ?? '');
            $layoutLabel = (string) ($layout['label'] ?? '');
            if ($layoutLabel === '') {
                $layoutLabel = $layoutName;
            }

            foreach ($this->subFields($layout) as $child) {
                foreach ($this->collect(
                    $child,
                    array($key),
                    array($name),
                    array($label),
                    '',
                    $key,
                    $layoutName,
                    $layoutKey,
                    $layoutLabel
                ) as $definition) {
                    $collected[] = $definition;
                }
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
        string $flexKey = '',
        string $layout = '',
        string $layoutKey = '',
        string $layoutLabel = '',
        string $cloneKey = '',
        string $cloneLabel = '',
        string $cloneDisplay = '',
    ): ?FieldDefinition {
        $postedKey = (string) ($field['key'] ?? '');
        $type      = (string) ($field['type'] ?? '');

        if ($postedKey === '' || !str_starts_with($postedKey, 'field_')) {
            return null;
        }

        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return null;
        }

        if ($type === 'select' && !empty($field['multiple'])) {
            return null;
        }

        $originalKey = $this->originalFieldKey($field);
        if ($originalKey === '' || !str_starts_with($originalKey, 'field_')) {
            return null;
        }

        $name  = (string) ($field['name'] ?? '');
        $label = (string) ($field['label'] ?? '');

        $path       = array();
        $pathNames  = array();
        $pathLabels = array();
        $container  = '';

        if ($ancestorKeys !== array()) {
            $leafLabel  = $this->leafLabel($field, $cloneKey, $label, $name, $originalKey);
            $path       = array_merge($ancestorKeys, array($postedKey));
            $pathNames  = array_merge($ancestorNames, array($name));
            $pathLabels = array_merge($ancestorLabels, array($leafLabel));
            if ($flexKey !== '') {
                $container = FieldRef::CONTAINER_FLEXIBLE;
            } elseif ($repeaterKey !== '') {
                $container = FieldRef::CONTAINER_REPEATER;
            } elseif ($cloneKey !== '') {
                $container = FieldRef::CONTAINER_CLONE;
            } else {
                $container = FieldRef::CONTAINER_GROUP;
            }
        }

        return new FieldDefinition(
            $originalKey,
            $name,
            $label,
            $type,
            $this->choices($field),
            $path,
            $container,
            $pathNames,
            $pathLabels,
            $repeaterKey,
            $layout,
            $layoutKey,
            $layoutLabel,
            $cloneKey,
            $cloneLabel,
            $cloneDisplay
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private function originalFieldKey(array $field): string
    {
        $backup = (string) ($field['__key'] ?? '');
        if ($backup !== '' && str_starts_with($backup, 'field_')) {
            return $backup;
        }

        return (string) ($field['key'] ?? '');
    }

    /**
     * @param array<string, mixed> $field
     */
    private function clonedFrom(array $field): string
    {
        $clone = (string) ($field['_clone'] ?? '');

        return $clone !== '' && str_starts_with($clone, 'field_') ? $clone : '';
    }

    /**
     * @param array<string, mixed> $field
     */
    private function leafLabel(array $field, string $cloneKey, string $label, string $name, string $key): string
    {
        if ($cloneKey !== '') {
            $original = trim((string) ($field['__label'] ?? ''));
            if ($original !== '') {
                return $original;
            }
        }

        return $label !== '' ? $label : ($name !== '' ? $name : $key);
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
            return is_array($loaded) ? $this->rehydrateSeamlessClones($this->onlyMaps($loaded)) : array();
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

        return $this->rehydrateSeamlessClones($fields);
    }

    /**
     * Fold ACF Seamless Clone children back under their Clone container.
     *
     * ACF's acf/get_fields splice replaces the Clone with its children.
     * Those children carry _clone and must not be treated as ordinary fields.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private function rehydrateSeamlessClones(array $fields, bool $insideClone = false): array
    {
        $out     = array();
        $pending = array();

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $type     = (string) ($field['type'] ?? '');
            $cloneKey = $this->clonedFrom($field);
            if ($cloneKey !== '' && $type !== FieldRef::CONTAINER_CLONE && !$insideClone) {
                if (!isset($pending[$cloneKey])) {
                    $pending[$cloneKey] = array();
                }
                $pending[$cloneKey][] = $field;
                continue;
            }

            $childInsideClone = $insideClone || $type === FieldRef::CONTAINER_CLONE;

            if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                $field['sub_fields'] = $this->rehydrateSeamlessClones(
                    $this->onlyMaps($field['sub_fields']),
                    $childInsideClone
                );
            }

            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layout) {
                    if (!is_array($layout) || !isset($layout['sub_fields']) || !is_array($layout['sub_fields'])) {
                        continue;
                    }

                    $field['layouts'][$layoutName]['sub_fields'] = $this->rehydrateSeamlessClones(
                        $this->onlyMaps($layout['sub_fields']),
                        $childInsideClone
                    );
                }
            }

            $out[] = $field;
        }

        foreach ($pending as $cloneKey => $children) {
            $out[] = $this->syntheticCloneContainer($cloneKey, $children);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function syntheticCloneContainer(string $cloneKey, array $children): array
    {
        $first = $children[0] ?? array();
        $name  = '';
        $label = '';

        if (function_exists('acf_get_field')) {
            $clone = acf_get_field($cloneKey);
            if (is_array($clone)) {
                $name  = (string) ($clone['name'] ?? '');
                $label = (string) ($clone['label'] ?? '');
            }
        }

        if ($name === '') {
            $backup = (string) ($first['__name'] ?? '');
            $child  = (string) ($first['name'] ?? '');
            if ($backup !== '' && $child !== '' && str_starts_with($child, $backup . '_')) {
                $name = $backup;
            }
        }

        if ($label === '') {
            $label = $name !== '' ? $name : $cloneKey;
        }

        return array(
            'key'        => $cloneKey,
            'name'       => $name,
            'label'      => $label,
            'type'       => FieldRef::CONTAINER_CLONE,
            'display'    => 'seamless',
            'sub_fields' => $children,
        );
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
