<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain\Operators;

use ContentGuard\Domain\Operators\NotEqualsOperator;
use PHPUnit\Framework\TestCase;

final class NotEqualsOperatorTest extends TestCase
{
    private NotEqualsOperator $operator;

    protected function setUp(): void
    {
        $this->operator = new NotEqualsOperator();
    }

    public function testMatchesWhenValuesDiffer(): void
    {
        $this->assertTrue($this->operator->matches('sauce', 'dip'));
        $this->assertTrue($this->operator->matches('Sauce', 'sauce'));
        $this->assertTrue($this->operator->matches(null, 'sauce'));
    }

    public function testDoesNotMatchWhenValuesAreEqual(): void
    {
        $this->assertFalse($this->operator->matches('sauce', 'sauce'));
        $this->assertFalse($this->operator->matches(5, '5'));
        $this->assertFalse($this->operator->matches(null, false));
    }
}
