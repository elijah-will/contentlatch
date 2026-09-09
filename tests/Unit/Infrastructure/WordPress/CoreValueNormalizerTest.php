<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Domain\Value;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Infrastructure\WordPress\CoreValueNormalizer;
use PHPUnit\Framework\TestCase;

final class CoreValueNormalizerTest extends TestCase
{
    private CoreValueNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CoreValueNormalizer();
    }

    public function testEmptyGutenbergMarkupIsEmpty(): void
    {
        $this->assertNull($this->normalizer->normalize(
            CoreFieldCatalog::CONTENT,
            "<!-- wp:paragraph -->\n<!-- /wp:paragraph -->"
        ));
        $this->assertTrue(Value::isEmpty($this->normalizer->normalize(
            CoreFieldCatalog::CONTENT,
            "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->"
        )));
    }

    public function testContentStripsTagsWithoutInflatingLength(): void
    {
        $value = $this->normalizer->normalize(CoreFieldCatalog::CONTENT, '<p>Hello&amp;co</p>');

        $this->assertSame('Hello&co', $value);
        $this->assertSame(8, Value::stringLength($value));
        $this->assertStringNotContainsString('<', (string) $value);
    }

    public function testFeaturedImageZeroIsEmptyAndDoesNotChangeValueIsEmpty(): void
    {
        $this->assertFalse($this->normalizer->normalize(CoreFieldCatalog::FEATURED_IMAGE, 0));
        $this->assertTrue($this->normalizer->normalize(CoreFieldCatalog::FEATURED_IMAGE, 42));
        $this->assertFalse(Value::isEmpty(0));
        $this->assertFalse(Value::isEmpty('0'));
        $this->assertTrue(Value::isEmpty(false));
        $this->assertFalse($this->normalizer->normalize(CoreFieldCatalog::FEATURED_IMAGE, array('id' => 15)));
        $this->assertFalse($this->normalizer->normalize(CoreFieldCatalog::FEATURED_IMAGE, 'yes'));
        $this->assertFalse($this->normalizer->normalize(CoreFieldCatalog::FEATURED_IMAGE, -1));
    }

    public function testAuthorIsTextNotNumber(): void
    {
        $this->assertSame('3', $this->normalizer->normalize(CoreFieldCatalog::AUTHOR, 3));
        $this->assertIsString($this->normalizer->normalize(CoreFieldCatalog::AUTHOR, 3));
        $this->assertNull($this->normalizer->normalize(CoreFieldCatalog::AUTHOR, 0));
    }
}
