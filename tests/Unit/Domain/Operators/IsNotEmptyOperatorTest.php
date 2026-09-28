<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Domain\Operators;

use ContentLatch\Domain\Operators\IsNotEmptyOperator;
use PHPUnit\Framework\TestCase;

final class IsNotEmptyOperatorTest extends TestCase
{
    private IsNotEmptyOperator $operator;

    protected function setUp(): void
    {
        $this->operator = new IsNotEmptyOperator();
    }

    public function testMatchesPresentValues(): void
    {
        $this->assertTrue($this->operator->matches('hello', null));
        $this->assertTrue($this->operator->matches(0, null));
        $this->assertTrue($this->operator->matches(array('a'), null));
    }

    public function testDoesNotMatchEmptyValues(): void
    {
        $this->assertFalse($this->operator->matches(null, null));
        $this->assertFalse($this->operator->matches('', null));
        $this->assertFalse($this->operator->matches(false, null));
        $this->assertFalse($this->operator->matches(array(), null));
    }
}
