<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use PHPUnit\Framework\TestCase;

final class AcfValueNormalizerTest extends TestCase
{
    private AcfValueNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AcfValueNormalizer();
    }

    /**
     * @dataProvider emptyProvider
     */
    public function testEmptyValuesForText(mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($value, 'text'));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function emptyProvider(): array
    {
        return array(
            'null'         => array(null, null),
            'false'        => array(false, null),
            'empty string' => array('', null),
            'empty array'  => array(array(), null),
            'zero int'     => array(0, 0),
            'zero string'  => array('0', '0'),
            'whitespace'   => array(' ', ' '),
        );
    }

    public function testTrueFalseNormalization(): void
    {
        $this->assertTrue($this->normalizer->normalize(1, 'true_false'));
        $this->assertTrue($this->normalizer->normalize('1', 'true_false'));
        $this->assertTrue($this->normalizer->normalize(true, 'true_false'));
        $this->assertFalse($this->normalizer->normalize(0, 'true_false'));
        $this->assertFalse($this->normalizer->normalize('0', 'true_false'));
        $this->assertFalse($this->normalizer->normalize(false, 'true_false'));
        $this->assertFalse($this->normalizer->normalize(null, 'true_false'));
    }

    public function testWysiwygStripsTagsAndDecodesEntities(): void
    {
        $html = '<p>Hello&nbsp;<strong>world</strong> &amp; friends</p>';
        $normalized = $this->normalizer->normalize($html, 'wysiwyg');

        $this->assertIsString($normalized);
        $this->assertStringNotContainsString('<', $normalized);
        $this->assertStringNotContainsString('&amp;', $normalized);
        $this->assertStringContainsString('world', $normalized);
        $this->assertStringContainsString('&', $normalized);
    }

    public function testWysiwygEmptyHtmlBecomesEmptyString(): void
    {
        $this->assertSame('', $this->normalizer->normalize('<p></p>', 'wysiwyg'));
    }

    public function testNumberKeepsScalarForm(): void
    {
        $this->assertSame(12, $this->normalizer->normalize(12, 'number'));
        $this->assertSame('12.5', $this->normalizer->normalize('12.5', 'number'));
        $this->assertSame(0, $this->normalizer->normalize(0, 'number'));
        $this->assertSame('0', $this->normalizer->normalize('0', 'number'));
        $this->assertNull($this->normalizer->normalize('1,000', 'number'));
        $this->assertNull($this->normalizer->normalize(' 5', 'number'));
    }

    public function testSelectUsesStoredValueNotLabel(): void
    {
        $this->assertSame('sauce', $this->normalizer->normalize('sauce', 'select'));
        $this->assertSame(
            'sauce',
            $this->normalizer->normalize(
                array(
                    'value' => 'sauce',
                    'label' => 'Sauce',
                ),
                'select'
            )
        );
        $this->assertNotSame(
            'Sauce',
            $this->normalizer->normalize(
                array(
                    'value' => 'sauce',
                    'label' => 'Sauce',
                ),
                'select'
            )
        );
    }

    public function testUnsupportedComplexValuesBecomeNull(): void
    {
        $this->assertNull($this->normalizer->normalize(array('tomatoes', 'salt'), 'text'));
        $this->assertNull($this->normalizer->normalize(array('id' => 1, 'name' => 'x'), 'relationship'));
        $this->assertNull($this->normalizer->normalize((object) array('a' => 1), 'textarea'));
        $this->assertNull($this->normalizer->normalize(array(array('field_x' => 'y')), 'wysiwyg'));
    }

    public function testRadioAndButtonGroupUseStoredValue(): void
    {
        $this->assertSame('external', $this->normalizer->normalize('external', 'radio'));
        $this->assertSame(
            'external',
            $this->normalizer->normalize(
                array(
                    'value' => 'external',
                    'label' => 'External Link',
                ),
                'button_group'
            )
        );
    }
}
