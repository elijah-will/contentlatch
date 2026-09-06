<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain;

use ContentGuard\Domain\Value;
use PHPUnit\Framework\TestCase;

final class ValueTest extends TestCase
{
    /**
     * @dataProvider emptyProvider
     */
    public function testIsEmpty(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Value::isEmpty($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function emptyProvider(): array
    {
        return array(
            'null'           => array(null, true),
            'false'          => array(false, true),
            'empty string'   => array('', true),
            'empty array'    => array(array(), true),
            'zero int'       => array(0, false),
            'zero string'    => array('0', false),
            'true'           => array(true, false),
            'text'           => array('sauce', false),
            'whitespace'     => array(' ', false),
            'nonempty array' => array(array('a'), false),
        );
    }

    public function testEqualsIsCaseSensitive(): void
    {
        $this->assertTrue(Value::equals('Sauce', 'Sauce'));
        $this->assertFalse(Value::equals('Sauce', 'sauce'));
    }

    public function testEqualsCoercesScalarNumbers(): void
    {
        $this->assertTrue(Value::equals(5, '5'));
        $this->assertTrue(Value::equals(0, '0'));
    }

    public function testEqualsTreatsBothEmptyAsEqual(): void
    {
        $this->assertTrue(Value::equals(null, ''));
        $this->assertTrue(Value::equals(false, null));
        $this->assertFalse(Value::equals(null, 'sauce'));
    }

    public function testEqualsRejectsNonScalars(): void
    {
        $this->assertFalse(Value::equals(array('a'), 'a'));
        $this->assertFalse(Value::equals(array('a'), array('a')));
    }

    public function testStringLength(): void
    {
        $this->assertSame(0, Value::stringLength(null));
        $this->assertSame(0, Value::stringLength(''));
        $this->assertSame(5, Value::stringLength('sauce'));
        $this->assertSame(1, Value::stringLength(0));
        $this->assertNull(Value::stringLength(array('a')));
    }
}
