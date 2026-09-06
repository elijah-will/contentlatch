<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain\Operators;

use ContentGuard\Domain\Operators\IsEmptyOperator;
use PHPUnit\Framework\TestCase;

final class IsEmptyOperatorTest extends TestCase
{
    private IsEmptyOperator $operator;

    protected function setUp(): void
    {
        $this->operator = new IsEmptyOperator();
    }

    public function testMatchesEmptyValues(): void
    {
        $this->assertTrue($this->operator->matches(null, null));
        $this->assertTrue($this->operator->matches('', 'ignored'));
        $this->assertTrue($this->operator->matches(false, null));
        $this->assertTrue($this->operator->matches(array(), null));
    }

    public function testDoesNotMatchPresentValues(): void
    {
        $this->assertFalse($this->operator->matches('sauce', null));
        $this->assertFalse($this->operator->matches(0, null));
        $this->assertFalse($this->operator->matches('0', null));
        $this->assertFalse($this->operator->matches(true, null));
    }
}
