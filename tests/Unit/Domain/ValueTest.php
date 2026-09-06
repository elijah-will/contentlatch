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

    /**
     * @dataProvider tryNumberProvider
     */
    public function testTryNumber(mixed $value, ?float $expected): void
    {
        $this->assertSame($expected, Value::tryNumber($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: ?float}>
     */
    public static function tryNumberProvider(): array
    {
        return array(
            'int 5'          => array(5, 5.0),
            'int 50'         => array(50, 50.0),
            'int 100'        => array(100, 100.0),
            'int 0'          => array(0, 0.0),
            'string 5'       => array('5', 5.0),
            'string 50'      => array('50', 50.0),
            'string 100'     => array('100', 100.0),
            'string 0'       => array('0', 0.0),
            'decimal'        => array('30.5', 30.5),
            'float'          => array(2.5, 2.5),
            'empty string'   => array('', null),
            'null'           => array(null, null),
            'false'          => array(false, null),
            'empty array'    => array(array(), null),
            'invalid text'   => array('thirty', null),
            'untrimmed'      => array(' 30', null),
            'comma'          => array('1,000', null),
            'bool true'      => array(true, null),
        );
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
