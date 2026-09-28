<?php
/**
 * Discovers native WordPress fields for a post type.
 *
 * Availability is supports-based. Internal/system types are excluded here
 * rather than by changing EditablePostTypes globally.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentLatch\Application\Integration\FieldCatalog;

final class CoreFieldCatalog implements FieldCatalog
{
    public const TITLE          = 'title';
    public const CONTENT        = 'content';
    public const EXCERPT        = 'excerpt';
    public const SLUG           = 'slug';
    public const FEATURED_IMAGE = 'featured_image';
    public const AUTHOR         = 'author';

    public const GROUP_LABEL = 'WordPress';

    /**
     * @var array<string, array{name: string, label: string, type: string, feature: string|null}>
     */
    public const FIELDS = array(
        self::TITLE => array(
            'name'    => 'post_title',
            'label'   => 'Title',
            'type'    => 'text',
            'feature' => 'title',
        ),
        self::CONTENT => array(
            'name'    => 'post_content',
            'label'   => 'Content',
            'type'    => 'wysiwyg',
            'feature' => 'editor',
        ),
        self::EXCERPT => array(
            'name'    => 'post_excerpt',
            'label'   => 'Excerpt',
            'type'    => 'textarea',
            'feature' => 'excerpt',
        ),
        self::SLUG => array(
            'name'    => 'post_name',
            'label'   => 'Slug',
            'type'    => 'text',
            'feature' => null,
        ),
        self::FEATURED_IMAGE => array(
            'name'    => 'featured_image',
            'label'   => 'Featured Image',
            'type'    => 'true_false',
            'feature' => 'thumbnail',
        ),
        self::AUTHOR => array(
            'name'    => 'post_author',
            'label'   => 'Author',
            'type'    => 'text',
            'feature' => 'author',
        ),
    );

    /**
     * WordPress internal/system types that must not expose Core fields.
     *
     * @var list<string>
     */
    private const EXCLUDED_POST_TYPES = array(
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_face',
        'wp_font_family',
        RulePostType::POST_TYPE,
    );

    /**
     * @param callable(string $postType, string $feature): bool|null $supports
     * @param callable(string $postType): bool|null $participates
     * @param callable(string $postType): bool|null $hasPermalinkUi
     */
    public function __construct(
        private mixed $supports = null,
        private mixed $participates = null,
        private mixed $hasPermalinkUi = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * @return list<string>
     */
    public static function excludedPostTypes(): array
    {
        return self::EXCLUDED_POST_TYPES;
    }

    public static function isExcludedPostType(string $postType): bool
    {
        return in_array($postType, self::EXCLUDED_POST_TYPES, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsForPostType(string $postType): array
    {
        if (!$this->participates($postType)) {
            return array();
        }

        $fields = array();
        foreach (self::FIELDS as $id => $definition) {
            if (!$this->isAvailable($postType, $id, $definition['feature'])) {
                continue;
            }

            $fields[] = array(
                'key'         => $id,
                'name'        => $definition['name'],
                'label'       => self::fieldLabel($id),
                'type'        => $definition['type'],
                'group_label' => __('WordPress', 'contentlatch'),
            );
        }

        return $fields;
    }

    private static function fieldLabel(string $id): string
    {
        return match ($id) {
            self::TITLE => __('Title', 'contentlatch'),
            self::CONTENT => __('Content', 'contentlatch'),
            self::EXCERPT => __('Excerpt', 'contentlatch'),
            self::SLUG => __('Slug', 'contentlatch'),
            self::FEATURED_IMAGE => __('Featured Image', 'contentlatch'),
            self::AUTHOR => __('Author', 'contentlatch'),
            default => self::FIELDS[$id]['label'] ?? $id,
        };
    }

    /**
     * @return array<string, string>
     */
    public function fieldTypesForPostType(string $postType): array
    {
        $types = array();
        foreach ($this->fieldsForPostType($postType) as $field) {
            $types[(string) $field['key']] = (string) $field['type'];
        }

        return $types;
    }

    private function isAvailable(string $postType, string $id, ?string $feature): bool
    {
        if ($id === self::SLUG) {
            return $this->hasPermalinkUi($postType);
        }

        if ($feature === null || $feature === '') {
            return false;
        }

        return $this->supports($postType, $feature);
    }

    private function participates(string $postType): bool
    {
        if (is_callable($this->participates)) {
            return (bool) ($this->participates)($postType);
        }

        return !self::isExcludedPostType($postType);
    }

    private function supports(string $postType, string $feature): bool
    {
        if (is_callable($this->supports)) {
            return (bool) ($this->supports)($postType, $feature);
        }

        return function_exists('post_type_supports') && post_type_supports($postType, $feature);
    }

    private function hasPermalinkUi(string $postType): bool
    {
        if (is_callable($this->hasPermalinkUi)) {
            return (bool) ($this->hasPermalinkUi)($postType);
        }

        if (!function_exists('get_post_type_object')) {
            return false;
        }

        $object = get_post_type_object($postType);
        if (!is_object($object)) {
            return false;
        }

        return !empty($object->public) || !empty($object->publicly_queryable);
    }
}
