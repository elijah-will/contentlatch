<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain\Operators;

use ContentGuard\Domain\Operators\DoesNotContainOperator;
use PHPUnit\Framework\TestCase;

final class DoesNotContainOperatorTest extends TestCase
{
    private DoesNotContainOperator $operator;

    protected function setUp(): void
    {
        $this->operator = new DoesNotContainOperator();
    }

    public function testMatchingValueReturnsFalse(): void
    {
        $this->assertFalse($this->operator->matches('B&G Foods', 'B&G'));
        $this->assertFalse($this->operator->matches('b&g foods', 'B&G'));
    }

    public function testNonMatchingValueReturnsTrue(): void
    {
        $this->assertTrue($this->operator->matches('Seasonings', 'B&G'));
    }

    public function testEmptyAndNullActualReturnTrue(): void
    {
        $this->assertTrue($this->operator->matches('', 'B&G'));
        $this->assertTrue($this->operator->matches(null, 'B&G'));
        $this->assertTrue($this->operator->matches(false, 'B&G'));
    }

    public function testNonScalarsReturnTrue(): void
    {
        $this->assertTrue($this->operator->matches(array('B&G'), 'B&G'));
    }
}
