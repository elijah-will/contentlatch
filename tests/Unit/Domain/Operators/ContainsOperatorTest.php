<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Domain\Operators;

use ContentLatch\Domain\Operators\ContainsOperator;
use PHPUnit\Framework\TestCase;

final class ContainsOperatorTest extends TestCase
{
    private ContainsOperator $operator;

    protected function setUp(): void
    {
        $this->operator = new ContainsOperator();
    }

    public function testExactMatch(): void
    {
        $this->assertTrue($this->operator->matches('healthy', 'healthy'));
    }

    public function testSubstringMatch(): void
    {
        $this->assertTrue($this->operator->matches('This is a healthy recipe.', 'healthy'));
        $this->assertFalse($this->operator->matches('This recipe is nutritious.', 'healthy'));
    }

    public function testCaseInsensitiveMatch(): void
    {
        $this->assertTrue($this->operator->matches('HEALTHY ingredients', 'healthy'));
        $this->assertTrue($this->operator->matches('healthy', 'HEALTHY'));
    }

    public function testEmptyAndNullActualDoNotMatch(): void
    {
        $this->assertFalse($this->operator->matches('', 'healthy'));
        $this->assertFalse($this->operator->matches(null, 'healthy'));
        $this->assertFalse($this->operator->matches(false, 'healthy'));
        $this->assertFalse($this->operator->matches(array(), 'healthy'));
    }

    public function testEmptyNeedleDoesNotMatch(): void
    {
        $this->assertFalse($this->operator->matches('healthy recipe', ''));
        $this->assertFalse($this->operator->matches('healthy recipe', null));
    }

    public function testLiteralSpecialCharacters(): void
    {
        $this->assertTrue($this->operator->matches('B&G Foods', 'B&G'));
        $this->assertFalse($this->operator->matches('BG Foods', 'B&G'));
        $this->assertTrue($this->operator->matches('50%', '%'));
    }

    public function testSubstringNotWordBoundary(): void
    {
        $this->assertTrue($this->operator->matches('healthy', 'health'));
    }

    public function testNonScalarsDoNotMatch(): void
    {
        $this->assertFalse($this->operator->matches(array('healthy'), 'healthy'));
    }

    public function testDoesNotStripHtml(): void
    {
        $this->assertTrue($this->operator->matches('<p>healthy</p>', 'healthy'));
        $this->assertTrue($this->operator->matches('<p>healthy</p>', '<p>'));
        $this->assertFalse($this->operator->matches('<p>healthy</p>', 'nutritious'));
    }
}
