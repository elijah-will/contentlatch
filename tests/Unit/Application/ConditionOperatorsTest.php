<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\ConditionOperators;
use PHPUnit\Framework\TestCase;

final class ConditionOperatorsTest extends TestCase
{
    public function testNumberFieldsReceiveNumericOperators(): void
    {
        $labels = ConditionOperators::labelsForFieldType('number');

        $this->assertArrayHasKey('greater_than', $labels);
        $this->assertArrayHasKey('greater_than_or_equal', $labels);
        $this->assertArrayHasKey('less_than', $labels);
        $this->assertArrayHasKey('less_than_or_equal', $labels);
        $this->assertArrayHasKey('equals', $labels);
        $this->assertArrayHasKey('is_empty', $labels);
        $this->assertSame('is greater than', $labels['greater_than']);
        $this->assertSame('is at least', $labels['greater_than_or_equal']);
        $this->assertTrue(ConditionOperators::isNumericField('range'));
    }

    public function testTextFieldsDoNotReceiveNumericOperators(): void
    {
        foreach (array('text', 'textarea', 'select', 'true_false', 'email') as $type) {
            $labels = ConditionOperators::labelsForFieldType($type);
            $this->assertArrayNotHasKey('greater_than', $labels, $type);
            $this->assertArrayNotHasKey('less_than', $labels, $type);
            $this->assertArrayHasKey('equals', $labels, $type);
            $this->assertArrayHasKey('not_equals', $labels, $type);
            $this->assertArrayHasKey('is_empty', $labels, $type);
            $this->assertArrayHasKey('is_not_empty', $labels, $type);
            $this->assertSame('is', $labels['equals']);
        }
    }
}
