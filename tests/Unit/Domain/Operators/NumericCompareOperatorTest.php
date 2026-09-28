<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Domain\Operators;

use ContentLatch\Domain\Operators\NumericCompareOperator;
use PHPUnit\Framework\TestCase;

final class NumericCompareOperatorTest extends TestCase
{
    /**
     * @dataProvider comparisonProvider
     */
    public function testNumericComparisons(string $comparison, mixed $value, mixed $operand, bool $expected): void
    {
        $operator = new NumericCompareOperator($comparison);
        $this->assertSame($expected, $operator->matches($value, $operand));
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: bool}>
     */
    public static function comparisonProvider(): array
    {
        return array(
            '5 > 30 false'           => array('>', 5, '30', false),
            '50 > 30 true'           => array('>', 50, '30', true),
            '100 > 30 true'          => array('>', 100, 30, true),
            '30 > 30 false'          => array('>', 30, '30', false),
            '30 >= 30 true'          => array('>=', 30, '30', true),
            '29 >= 30 false'         => array('>=', 29, 30, false),
            '5 < 30 true'            => array('<', 5, '30', true),
            '50 < 30 false'          => array('<', 50, '30', false),
            '30 <= 30 true'          => array('<=', 30, '30', true),
            '31 <= 30 false'         => array('<=', 31, 30, false),
            '0 > 0 false'            => array('>', 0, '0', false),
            '0 >= 0 true'            => array('>=', 0, '0', true),
            '1 > 0 true'             => array('>', 1, '0', true),
            'decimal 2.5 > 2'        => array('>', '2.5', '2', true),
            'decimal 2.5 < 2.5 false'=> array('<', 2.5, '2.5', false),
            'empty != 0'             => array('>', '', '0', false),
            'empty < 0 false'        => array('<', '', '0', false),
            '0 > empty false'        => array('>', 0, '', false),
            'null > 0 false'         => array('>', null, '0', false),
            'invalid != 0'           => array('>', 'abc', '0', false),
            'invalid > 30 false'     => array('>', 'abc', '30', false),
            '5 > invalid false'      => array('>', 5, 'thirty', false),
            'untrimmed not zero'     => array('>', ' 5', '0', false),
        );
    }
}
