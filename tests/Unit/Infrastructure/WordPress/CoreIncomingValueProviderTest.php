<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Domain\FieldInstance;
use ContentGuard\Domain\Value;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Infrastructure\WordPress\CoreIncomingValueProvider;
use ContentGuard\Infrastructure\WordPress\CoreValueNormalizer;
use ContentGuard\Tests\Support\CoreCatalogFixtures;
use PHPUnit\Framework\TestCase;

final class CoreIncomingValueProviderTest extends TestCase
{
    public function testTitlePresentAndEmpty(): void
    {
        $present = $this->provider(array('title' => 'Hello'));
        $this->assertTrue($present->has(CoreFieldCatalog::TITLE));
        $this->assertSame('Hello', $present->get(CoreFieldCatalog::TITLE));

        $empty = $this->provider(array('title' => ''));
        $this->assertTrue($empty->has(CoreFieldCatalog::TITLE));
        $this->assertNull($empty->get(CoreFieldCatalog::TITLE));
        $this->assertTrue(Value::isEmpty($empty->get(CoreFieldCatalog::TITLE)));
    }

    public function testGutenbergRestTitleObjectIsReadAsText(): void
    {
        $fromRest = $this->provider(array(
            'title' => array(
                'raw'      => 'This is a valid title',
                'rendered' => 'This is a valid title',
            ),
        ));

        $this->assertSame('This is a valid title', $fromRest->get(CoreFieldCatalog::TITLE));
        $this->assertSame(21, Value::stringLength($fromRest->get(CoreFieldCatalog::TITLE)));
        $this->assertSame(5, Value::stringLength($this->provider(array(
            'title' => array('raw' => 'Short'),
        ))->get(CoreFieldCatalog::TITLE)));
    }

    public function testTitleAcceptsPostTitleAlias(): void
    {
        $provider = $this->provider(array('post_title' => 'From WP'));
        $this->assertTrue($provider->has(CoreFieldCatalog::TITLE));
        $this->assertSame('From WP', $provider->get(CoreFieldCatalog::TITLE));
    }

    public function testCoreIdWinsOverWordpressAlias(): void
    {
        $provider = $this->provider(array(
            'title'      => 'Core id',
            'post_title' => 'WP alias',
        ));

        $this->assertSame('Core id', $provider->get(CoreFieldCatalog::TITLE));
    }

    public function testContentPresentEmptyAndGutenbergMarkup(): void
    {
        $present = $this->provider(array('content' => '<p>Hello world</p>'));
        $this->assertSame('Hello world', $present->get(CoreFieldCatalog::CONTENT));
        $this->assertFalse(Value::isEmpty($present->get(CoreFieldCatalog::CONTENT)));

        $empty = $this->provider(array('content' => ''));
        $this->assertNull($empty->get(CoreFieldCatalog::CONTENT));

        $gutenbergEmpty = $this->provider(array(
            'content' => "<!-- wp:paragraph -->\n<!-- /wp:paragraph -->",
        ));
        $this->assertTrue(Value::isEmpty($gutenbergEmpty->get(CoreFieldCatalog::CONTENT)));

        $gutenbergPresent = $this->provider(array(
            'content' => "<!-- wp:paragraph -->\n<p>Keep this</p>\n<!-- /wp:paragraph -->",
        ));
        $this->assertSame('Keep this', $gutenbergPresent->get(CoreFieldCatalog::CONTENT));
        $this->assertSame(9, Value::stringLength($gutenbergPresent->get(CoreFieldCatalog::CONTENT)));
    }

    public function testGutenbergRestContentObjectIsNotTreatedAsEmpty(): void
    {
        $markup = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
        $provider = $this->provider(array(
            'content' => array(
                'raw'           => $markup,
                'rendered'      => '<p>Hello world</p>',
                'protected'     => false,
                'block_version' => 1,
            ),
        ));

        $this->assertTrue($provider->has(CoreFieldCatalog::CONTENT));
        $this->assertSame('Hello world', $provider->get(CoreFieldCatalog::CONTENT));
        $this->assertFalse(Value::isEmpty($provider->get(CoreFieldCatalog::CONTENT)));
    }

    public function testSanitizedEmptyContentArrayFallsThroughToPostContent(): void
    {
        $provider = $this->provider(array(
            'content'      => array(),
            'post_content' => "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->",
        ));

        $this->assertSame('Hello world', $provider->get(CoreFieldCatalog::CONTENT));
    }

    public function testContentHtmlIsNotCountedAsCharacters(): void
    {
        $provider = $this->provider(array('content' => '<p><strong>Hi</strong></p>'));

        $this->assertSame('Hi', $provider->get(CoreFieldCatalog::CONTENT));
        $this->assertSame(2, Value::stringLength($provider->get(CoreFieldCatalog::CONTENT)));
    }

    public function testExcerptPresentAndEmpty(): void
    {
        $present = $this->provider(array('excerpt' => 'Stored excerpt'));
        $this->assertSame('Stored excerpt', $present->get(CoreFieldCatalog::EXCERPT));

        $empty = $this->provider(array('post_excerpt' => ''));
        $this->assertTrue($empty->has(CoreFieldCatalog::EXCERPT));
        $this->assertNull($empty->get(CoreFieldCatalog::EXCERPT));
    }

    public function testSlugPresentEmptyAndOmittedWithoutGenerating(): void
    {
        $present = $this->provider(array('slug' => 'hello-world'));
        $this->assertSame('hello-world', $present->get(CoreFieldCatalog::SLUG));

        $empty = $this->provider(array('post_name' => ''));
        $this->assertTrue($empty->has(CoreFieldCatalog::SLUG));
        $this->assertNull($empty->get(CoreFieldCatalog::SLUG));

        $omitted = $this->provider(array('title' => 'Hello'));
        $this->assertFalse($omitted->has(CoreFieldCatalog::SLUG));
        $this->assertNull($omitted->get(CoreFieldCatalog::SLUG));
        $this->assertTrue(Value::isEmpty($omitted->get(CoreFieldCatalog::SLUG)));
    }

    public function testFeaturedImagePresentMissingAndInvalid(): void
    {
        $present = $this->provider(array('featured_image' => 15));
        $this->assertTrue($present->get(CoreFieldCatalog::FEATURED_IMAGE));
        $this->assertFalse(Value::isEmpty($present->get(CoreFieldCatalog::FEATURED_IMAGE)));

        $thumbnail = $this->provider(array('_thumbnail_id' => '22'));
        $this->assertTrue($thumbnail->get(CoreFieldCatalog::FEATURED_IMAGE));

        $media = $this->provider(array('featured_media' => 9));
        $this->assertTrue($media->get(CoreFieldCatalog::FEATURED_IMAGE));

        $missing = $this->provider(array('featured_image' => 0));
        $this->assertFalse($missing->get(CoreFieldCatalog::FEATURED_IMAGE));
        $this->assertTrue(Value::isEmpty($missing->get(CoreFieldCatalog::FEATURED_IMAGE)));

        $unset = $this->provider(array('_thumbnail_id' => '-1'));
        $this->assertFalse($unset->get(CoreFieldCatalog::FEATURED_IMAGE));

        $notAnId = $this->provider(array('featured_image' => 'yes'));
        $this->assertFalse($notAnId->get(CoreFieldCatalog::FEATURED_IMAGE));
        $this->assertTrue(Value::isEmpty($notAnId->get(CoreFieldCatalog::FEATURED_IMAGE)));

        $omitted = $this->provider(array('title' => 'Hello'));
        $this->assertFalse($omitted->has(CoreFieldCatalog::FEATURED_IMAGE));
    }

    public function testAuthorPresentAndEmpty(): void
    {
        $present = $this->provider(array('author' => 7));
        $this->assertSame('7', $present->get(CoreFieldCatalog::AUTHOR));
        $this->assertIsString($present->get(CoreFieldCatalog::AUTHOR));
        $this->assertFalse(Value::isEmpty($present->get(CoreFieldCatalog::AUTHOR)));

        $fromWp = $this->provider(array('post_author' => '3'));
        $this->assertSame('3', $fromWp->get(CoreFieldCatalog::AUTHOR));

        $zero = $this->provider(array('author' => 0));
        $this->assertNull($zero->get(CoreFieldCatalog::AUTHOR));
        $this->assertTrue(Value::isEmpty($zero->get(CoreFieldCatalog::AUTHOR)));
    }

    public function testHasClaimsOnlyCataloguedCoreIdsPresentInPayload(): void
    {
        $provider = $this->provider(
            array('title' => 'Hello', 'field_123' => 'nope'),
            array(CoreFieldCatalog::TITLE => 'text')
        );

        $this->assertTrue($provider->has(CoreFieldCatalog::TITLE));
        $this->assertFalse($provider->has(CoreFieldCatalog::CONTENT));
        $this->assertFalse($provider->has('field_123'));
        $this->assertFalse($provider->has('field_ingredients'));
        $this->assertNull($provider->get('field_123'));
    }

    public function testUnsupportedPostTypeFieldIsNotOwnedEvenIfPayloadContainsIt(): void
    {
        $provider = $this->provider(
            array(
                'title'   => 'Hello',
                'content' => '<p>Body</p>',
            ),
            array(CoreFieldCatalog::TITLE => 'text')
        );

        $this->assertTrue($provider->has(CoreFieldCatalog::TITLE));
        $this->assertFalse($provider->has(CoreFieldCatalog::CONTENT));
        $this->assertNull($provider->get(CoreFieldCatalog::CONTENT));
    }

    public function testMalformedPayloadIsTreatedAsEmpty(): void
    {
        foreach (array(null, 'post', 123, new \stdClass()) as $payload) {
            $provider = $this->provider($payload);
            $this->assertFalse($provider->has(CoreFieldCatalog::TITLE));
            $this->assertNull($provider->get(CoreFieldCatalog::TITLE));
        }
    }

    public function testInstancesReturnsOneScalarFieldInstance(): void
    {
        $provider = $this->provider(array('title' => 'Hello'));

        $this->assertEquals(array(new FieldInstance('Hello')), $provider->instances(CoreFieldCatalog::TITLE));
        $this->assertCount(1, $provider->instances(CoreFieldCatalog::TITLE));
        $this->assertSame(array(), $provider->instances(CoreFieldCatalog::CONTENT));
        $this->assertSame(array(), $provider->instances('field_123'));
    }

    public function testDoesNotReadSuperglobalsOrStoredPostApis(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/WordPress/CoreIncomingValueProvider.php');

        $this->assertStringNotContainsString('$_POST', $src);
        $this->assertStringNotContainsString('$_REQUEST', $src);
        $this->assertStringNotContainsString('get_post_field', $src);
        $this->assertStringNotContainsString('get_the_title(', $src);
        $this->assertStringNotContainsString('get_the_excerpt(', $src);
        $this->assertStringNotContainsString('$wpdb', $src);
    }

    /**
     * @param array<string, string>|null $types
     */
    private function provider(mixed $payload, ?array $types = null): CoreIncomingValueProvider
    {
        return new CoreIncomingValueProvider(
            $payload,
            $types ?? CoreCatalogFixtures::fullPost()->fieldTypesForPostType('post'),
            new CoreValueNormalizer()
        );
    }
}
