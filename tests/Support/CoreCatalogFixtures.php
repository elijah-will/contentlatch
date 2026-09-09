<?php
/**
 * Deterministic Core catalog/provider fixtures for Phase 12A tests.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Domain\FieldRef;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Infrastructure\WordPress\CoreIntegration;

final class CoreCatalogFixtures
{
    /**
     * @param array<string, list<string>> $supportsByType
     * @param list<string> $permalinkTypes
     */
    public static function catalog(
        array $supportsByType,
        array $permalinkTypes = array(),
        ?array $excluded = null,
    ): CoreFieldCatalog {
        $excluded ??= CoreFieldCatalog::excludedPostTypes();

        return new CoreFieldCatalog(
            static fn (string $type, string $feature): bool => in_array(
                $feature,
                $supportsByType[$type] ?? array(),
                true
            ),
            static fn (string $type): bool => !in_array($type, $excluded, true),
            static fn (string $type): bool => in_array($type, $permalinkTypes, true)
        );
    }

    public static function fullPost(string $postType = 'post'): CoreFieldCatalog
    {
        return self::catalog(
            array(
                $postType => array('title', 'editor', 'excerpt', 'thumbnail', 'author'),
            ),
            array($postType)
        );
    }

    public static function titleOnly(string $postType = 'notice'): CoreFieldCatalog
    {
        return self::catalog(
            array($postType => array('title')),
            array()
        );
    }

    public static function integration(?CoreFieldCatalog $catalog = null): CoreIntegration
    {
        return new CoreIntegration($catalog ?? self::fullPost());
    }

    public static function titleRef(): FieldRef
    {
        return new FieldRef(CoreFieldCatalog::TITLE, 'post_title', 'Title');
    }

    public static function contentRef(): FieldRef
    {
        return new FieldRef(CoreFieldCatalog::CONTENT, 'post_content', 'Content');
    }

    public static function excerptRef(): FieldRef
    {
        return new FieldRef(CoreFieldCatalog::EXCERPT, 'post_excerpt', 'Excerpt');
    }

    public static function slugRef(): FieldRef
    {
        return new FieldRef(CoreFieldCatalog::SLUG, 'post_name', 'Slug');
    }

    public static function featuredImageRef(): FieldRef
    {
        return new FieldRef(CoreFieldCatalog::FEATURED_IMAGE, 'featured_image', 'Featured Image');
    }

    public static function authorRef(): FieldRef
    {
        return new FieldRef(CoreFieldCatalog::AUTHOR, 'post_author', 'Author');
    }
}
