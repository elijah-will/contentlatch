<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

use ContentLatch\Infrastructure\WordPress\CoreFieldCatalog;
use ContentLatch\Infrastructure\WordPress\RulePostType;
use ContentLatch\Tests\Support\CoreCatalogFixtures;
use PHPUnit\Framework\TestCase;

final class CoreFieldCatalogTest extends TestCase
{
    public function testFullSupportExposesEveryCoreField(): void
    {
        $catalog = CoreCatalogFixtures::fullPost();
        $fields  = $this->byId($catalog->fieldsForPostType('post'));

        $this->assertSame(
            array(
                CoreFieldCatalog::TITLE,
                CoreFieldCatalog::CONTENT,
                CoreFieldCatalog::EXCERPT,
                CoreFieldCatalog::SLUG,
                CoreFieldCatalog::FEATURED_IMAGE,
                CoreFieldCatalog::AUTHOR,
            ),
            array_keys($fields)
        );
        $this->assertSame(
            array(
                CoreFieldCatalog::TITLE          => 'text',
                CoreFieldCatalog::CONTENT        => 'wysiwyg',
                CoreFieldCatalog::EXCERPT        => 'textarea',
                CoreFieldCatalog::SLUG           => 'text',
                CoreFieldCatalog::FEATURED_IMAGE => 'true_false',
                CoreFieldCatalog::AUTHOR         => 'text',
            ),
            $catalog->fieldTypesForPostType('post')
        );
    }

    public function testTitleOnlySupportExposesOnlyTitle(): void
    {
        $catalog = CoreCatalogFixtures::titleOnly('notice');
        $fields  = $catalog->fieldsForPostType('notice');

        $this->assertCount(1, $fields);
        $this->assertSame(CoreFieldCatalog::TITLE, $fields[0]['key']);
        $this->assertSame('Title', $fields[0]['label']);
        $this->assertSame(array(CoreFieldCatalog::TITLE => 'text'), $catalog->fieldTypesForPostType('notice'));
        $this->assertSame(array(), $catalog->fieldsForPostType('post'));
    }

    public function testFieldMetadataIsStableAndUnprefixed(): void
    {
        $field = $this->byId(CoreCatalogFixtures::fullPost()->fieldsForPostType('post'))[CoreFieldCatalog::TITLE];

        $this->assertSame('title', $field['key']);
        $this->assertSame('post_title', $field['name']);
        $this->assertSame('Title', $field['label']);
        $this->assertSame('text', $field['type']);
        $this->assertSame('WordPress', $field['group_label']);
        $this->assertArrayNotHasKey('integration', $field);
        $this->assertStringNotContainsString('core:', $field['key']);
        $this->assertStringNotContainsString('wp:', $field['key']);
        $this->assertStringNotContainsString('wordpress:', $field['key']);

        $content = $this->byId(CoreCatalogFixtures::fullPost()->fieldsForPostType('post'))[CoreFieldCatalog::CONTENT];
        $this->assertSame('content', $content['key']);
        $this->assertSame('post_content', $content['name']);
        $this->assertSame('Content', $content['label']);
        $this->assertSame('wysiwyg', $content['type']);

        $excerpt = $this->byId(CoreCatalogFixtures::fullPost()->fieldsForPostType('post'))[CoreFieldCatalog::EXCERPT];
        $this->assertSame('excerpt', $excerpt['key']);
        $this->assertSame('post_excerpt', $excerpt['name']);
        $this->assertSame('Excerpt', $excerpt['label']);
        $this->assertSame('textarea', $excerpt['type']);

        $slug = $this->byId(CoreCatalogFixtures::fullPost()->fieldsForPostType('post'))[CoreFieldCatalog::SLUG];
        $this->assertSame('slug', $slug['key']);
        $this->assertSame('post_name', $slug['name']);
        $this->assertSame('Slug', $slug['label']);
        $this->assertSame('text', $slug['type']);

        $image = $this->byId(CoreCatalogFixtures::fullPost()->fieldsForPostType('post'))[CoreFieldCatalog::FEATURED_IMAGE];
        $this->assertSame('featured_image', $image['key']);
        $this->assertSame('featured_image', $image['name']);
        $this->assertSame('Featured Image', $image['label']);
        $this->assertSame('true_false', $image['type']);

        $author = $this->byId(CoreCatalogFixtures::fullPost()->fieldsForPostType('post'))[CoreFieldCatalog::AUTHOR];
        $this->assertSame('author', $author['key']);
        $this->assertSame('post_author', $author['name']);
        $this->assertSame('Author', $author['label']);
        $this->assertSame('text', $author['type']);
    }

    public function testSlugRequiresPermalinkUiEvenWhenOtherFeaturesAreSupported(): void
    {
        $catalog = CoreCatalogFixtures::catalog(
            array('private_cpt' => array('title', 'editor', 'excerpt', 'thumbnail', 'author')),
            array()
        );
        $ids = array_column($catalog->fieldsForPostType('private_cpt'), 'key');

        $this->assertContains(CoreFieldCatalog::TITLE, $ids);
        $this->assertContains(CoreFieldCatalog::CONTENT, $ids);
        $this->assertNotContains(CoreFieldCatalog::SLUG, $ids);
    }

    public function testAttachmentAndInternalTypesAreExcluded(): void
    {
        $supports = array('title', 'editor', 'excerpt', 'thumbnail', 'author');
        $types    = array_merge(
            CoreFieldCatalog::excludedPostTypes(),
            array(RulePostType::POST_TYPE)
        );
        $map = array();
        foreach ($types as $type) {
            $map[$type] = $supports;
        }

        $catalog = CoreCatalogFixtures::catalog($map, $types);
        foreach ($types as $type) {
            $this->assertSame(array(), $catalog->fieldsForPostType($type), $type . ' must not expose Core fields');
        }

        $this->assertTrue(CoreFieldCatalog::isExcludedPostType('attachment'));
        $this->assertTrue(CoreFieldCatalog::isExcludedPostType(RulePostType::POST_TYPE));
        $this->assertTrue(CoreFieldCatalog::isExcludedPostType('wp_block'));
        $this->assertTrue(CoreFieldCatalog::isExcludedPostType('wp_font_family'));
        $this->assertFalse(CoreFieldCatalog::isExcludedPostType('post'));
        $this->assertFalse(CoreFieldCatalog::isExcludedPostType('book'));
    }

    public function testCustomPostTypeWithoutAcfStillExposesSupportedCoreFields(): void
    {
        $catalog = CoreCatalogFixtures::catalog(
            array('book' => array('title', 'editor')),
            array('book')
        );
        $ids = array_column($catalog->fieldsForPostType('book'), 'key');

        $this->assertSame(
            array(CoreFieldCatalog::TITLE, CoreFieldCatalog::CONTENT, CoreFieldCatalog::SLUG),
            $ids
        );
    }

    public function testDefaultCatalogUsesPostTypeSupportsWhenWordPressApisAreAbsent(): void
    {
        $catalog = new CoreFieldCatalog();

        $this->assertSame(array(), $catalog->fieldsForPostType('post'));
        $this->assertSame(array(), $catalog->fieldsForPostType('attachment'));
    }

    public function testProductionCatalogUsesPublicWordpressSupportApis(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/WordPress/CoreFieldCatalog.php');

        $this->assertStringContainsString('post_type_supports', $src);
        $this->assertStringContainsString('get_post_type_object', $src);
        $this->assertStringNotContainsString('$wpdb', $src);
        $this->assertStringNotContainsString('wp_posts', $src);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $fields): array
    {
        $byId = array();
        foreach ($fields as $field) {
            $byId[(string) $field['key']] = $field;
        }

        return $byId;
    }
}
