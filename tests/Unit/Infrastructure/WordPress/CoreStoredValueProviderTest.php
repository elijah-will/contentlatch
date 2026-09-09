<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Domain\FieldInstance;
use ContentGuard\Domain\Value;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Infrastructure\WordPress\CoreStoredValueProvider;
use ContentGuard\Infrastructure\WordPress\CoreValueNormalizer;
use ContentGuard\Tests\Support\CoreCatalogFixtures;
use PHPUnit\Framework\TestCase;

final class CoreStoredValueProviderTest extends TestCase
{
    public function testTitlePopulatedAndEmpty(): void
    {
        $this->assertSame('Hello', $this->get('title', 'Hello'));
        $this->assertNull($this->get('title', ''));
        $this->assertNull($this->get('title', null));
        $this->assertTrue(Value::isEmpty($this->get('title', '')));
        $this->assertFalse(Value::isEmpty($this->get('title', 'Hello')));
    }

    public function testContentPopulatedEmptyAndGutenbergMarkup(): void
    {
        $this->assertSame('Hello world', $this->get('content', '<p>Hello world</p>'));
        $this->assertNull($this->get('content', ''));
        $this->assertNull($this->get('content', "<!-- wp:paragraph -->\n<!-- /wp:paragraph -->"));
        $this->assertNull($this->get('content', "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->"));
        $this->assertTrue(Value::isEmpty($this->get('content', "<!-- wp:paragraph -->\n<!-- /wp:paragraph -->")));
    }

    public function testContentHtmlIsNotCountedAsCharacters(): void
    {
        $value = $this->get('content', '<p><strong>Hi</strong></p>');

        $this->assertSame('Hi', $value);
        $this->assertSame(2, Value::stringLength($value));
        $this->assertSame(2, Value::stringLength($this->get('content', '<div class="wp-block-paragraph">Hi</div>')));
    }

    public function testExcerptUsesStoredValueNotGeneratedContent(): void
    {
        $this->assertSame('Stored excerpt', $this->get('excerpt', 'Stored excerpt'));
        $this->assertNull($this->get('excerpt', ''));
        $this->assertNull($this->get('excerpt', null));
    }

    public function testSlugPopulatedAndEmptyWithoutGenerating(): void
    {
        $this->assertSame('hello-world', $this->get('slug', 'hello-world'));
        $this->assertNull($this->get('slug', ''));
    }

    public function testMissingThumbnailBecomesEmptyAndPresentThumbnailIsTruthy(): void
    {
        $missing = $this->get('featured_image', 0);
        $present = $this->get('featured_image', 15);

        $this->assertFalse($missing);
        $this->assertTrue(Value::isEmpty($missing));
        $this->assertTrue($present);
        $this->assertFalse(Value::isEmpty($present));
        $this->assertFalse(Value::isEmpty(0), 'Value::isEmpty must remain unchanged for integer 0');
    }

    public function testAuthorZeroIsEmptyAndValidIdBecomesString(): void
    {
        $this->assertNull($this->get('author', 0));
        $this->assertNull($this->get('author', '0'));
        $this->assertTrue(Value::isEmpty($this->get('author', 0)));
        $this->assertSame('7', $this->get('author', 7));
        $this->assertSame('7', $this->get('author', '7'));
        $this->assertFalse(Value::isEmpty($this->get('author', 7)));
    }

    public function testHasClaimsOnlyCataloguedCoreIds(): void
    {
        $titleOnly = $this->provider(
            array(CoreFieldCatalog::TITLE => 'text'),
            array(CoreFieldCatalog::TITLE => 'Hello')
        );

        $this->assertTrue($titleOnly->has(CoreFieldCatalog::TITLE));
        $this->assertFalse($titleOnly->has(CoreFieldCatalog::CONTENT));
        $this->assertFalse($titleOnly->has(CoreFieldCatalog::EXCERPT));
        $this->assertFalse($titleOnly->has(CoreFieldCatalog::SLUG));
        $this->assertFalse($titleOnly->has(CoreFieldCatalog::FEATURED_IMAGE));
        $this->assertFalse($titleOnly->has(CoreFieldCatalog::AUTHOR));
        $this->assertFalse($titleOnly->has('field_123'));
        $this->assertFalse($titleOnly->has('field_ingredients'));
        $this->assertFalse($titleOnly->has('unknown'));
        $this->assertNull($titleOnly->get('field_123'));
    }

    public function testInstancesReturnsOneScalarFieldInstance(): void
    {
        $provider = $this->provider(
            CoreCatalogFixtures::fullPost()->fieldTypesForPostType('post'),
            array(CoreFieldCatalog::TITLE => 'Hello')
        );

        $this->assertEquals(array(new FieldInstance('Hello')), $provider->instances(CoreFieldCatalog::TITLE));
        $this->assertCount(1, $provider->instances(CoreFieldCatalog::TITLE));
        $this->assertEquals(array(new FieldInstance(null)), $provider->instances(CoreFieldCatalog::CONTENT));
        $this->assertSame(array(), $provider->instances('field_123'));
    }

    public function testMissingReaderCannotResolveWithoutWordpressApis(): void
    {
        $provider = new CoreStoredValueProvider(
            1,
            array(CoreFieldCatalog::TITLE => 'text'),
            new CoreValueNormalizer()
        );

        $this->assertFalse($provider->has(CoreFieldCatalog::TITLE));
        $this->assertNull($provider->get(CoreFieldCatalog::TITLE));
        $this->assertSame(array(), $provider->instances(CoreFieldCatalog::TITLE));
    }

    public function testProductionReadsUseGetPostFieldNotPresentationHelpers(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/WordPress/CoreStoredValueProvider.php');

        $this->assertStringContainsString("postField('post_title'", $src);
        $this->assertStringContainsString("postField('post_content'", $src);
        $this->assertStringContainsString("postField('post_excerpt'", $src);
        $this->assertStringContainsString("postField('post_name'", $src);
        $this->assertStringContainsString("postField('post_author'", $src);
        $this->assertStringContainsString('get_post_field', $src);
        $this->assertStringContainsString('get_post_thumbnail_id', $src);
        $this->assertStringNotContainsString('get_the_title(', $src);
        $this->assertStringNotContainsString('get_the_excerpt(', $src);
        $this->assertStringNotContainsString('$wpdb', $src);
        $this->assertStringNotContainsString('wp_posts', $src);
        $this->assertStringNotContainsString('wp_postmeta', $src);
    }

    /**
     * @param array<string, string> $types
     * @param array<string, mixed>  $store
     */
    private function provider(array $types, array $store): CoreStoredValueProvider
    {
        return new CoreStoredValueProvider(
            15,
            $types,
            new CoreValueNormalizer(),
            static function (string $id) use ($store): mixed {
                return $store[$id] ?? null;
            }
        );
    }

    private function get(string $fieldId, mixed $raw): mixed
    {
        $types = CoreCatalogFixtures::fullPost()->fieldTypesForPostType('post');

        return $this->provider($types, array($fieldId => $raw))->get($fieldId);
    }
}
