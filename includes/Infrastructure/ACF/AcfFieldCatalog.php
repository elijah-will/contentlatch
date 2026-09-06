<?php
/**
 * Discovers V1-supported ACF fields for a post type.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

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
            $definition = $this->mapField($field);
            if ($definition !== null) {
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
     * @param array<string, mixed> $field
     */
    private function mapField(array $field): ?FieldDefinition
    {
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

        return new FieldDefinition(
            $key,
            (string) ($field['name'] ?? ''),
            (string) ($field['label'] ?? ''),
            $type,
            $this->choices($field),
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
