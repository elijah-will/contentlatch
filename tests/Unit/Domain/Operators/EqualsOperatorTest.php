<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Domain\Operators;

use ContentLatch\Domain\Operators\EqualsOperator;
use PHPUnit\Framework\TestCase;

final class EqualsOperatorTest extends TestCase
{
    private EqualsOperator $operator;

    protected function setUp(): void
    {
        $this->operator = new EqualsOperator();
    }

    public function testMatchesEqualScalars(): void
    {
        $this->assertTrue($this->operator->matches('sauce', 'sauce'));
        $this->assertTrue($this->operator->matches(1, '1'));
        $this->assertTrue($this->operator->matches(true, '1'));
    }

    public function testDoesNotMatchDifferentValues(): void
    {
        $this->assertFalse($this->operator->matches('sauce', 'dip'));
        $this->assertFalse($this->operator->matches('Sauce', 'sauce'));
    }

    public function testMissingAndNullMatchEmptyOperand(): void
    {
        $this->assertTrue($this->operator->matches(null, ''));
        $this->assertTrue($this->operator->matches(false, null));
        $this->assertFalse($this->operator->matches(null, 'sauce'));
    }
}
