<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain\Validators;

use ContentGuard\Domain\Validators\MinLengthValidator;
use PHPUnit\Framework\TestCase;

final class MinLengthValidatorTest extends TestCase
{
    private MinLengthValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MinLengthValidator();
    }

    public function testPassesWhenLongEnough(): void
    {
        $outcome = $this->validator->validate(str_repeat('a', 100), array('min' => 100));
        $this->assertTrue($outcome->passed);
        $this->assertSame(100, $outcome->context['length']);
    }

    public function testFailsWhenTooShort(): void
    {
        $outcome = $this->validator->validate('short', array('min' => 100));
        $this->assertFalse($outcome->passed);
        $this->assertSame('min_length', $outcome->code);
        $this->assertSame(5, $outcome->context['length']);
    }

    public function testEmptyHasLengthZero(): void
    {
        $outcome = $this->validator->validate(null, array('min' => 1));
        $this->assertFalse($outcome->passed);
        $this->assertSame(0, $outcome->context['length']);
    }

    public function testFailsForNonScalar(): void
    {
        $outcome = $this->validator->validate(array('a', 'b'), array('min' => 1));
        $this->assertFalse($outcome->passed);
        $this->assertSame('invalid_type', $outcome->code);
    }

    public function testFailsWhenMinMissing(): void
    {
        $outcome = $this->validator->validate('hello', array());
        $this->assertFalse($outcome->passed);
        $this->assertSame('invalid_params', $outcome->code);
    }

    public function testFiftyCharacterBoundary(): void
    {
        $tooShort = $this->validator->validate(str_repeat('a', 10), array('min' => 50));
        $this->assertFalse($tooShort->passed);
        $this->assertSame('min_length', $tooShort->code);
        $this->assertSame(10, $tooShort->context['length']);

        $exact = $this->validator->validate(str_repeat('a', 50), array('min' => 50));
        $this->assertTrue($exact->passed);
        $this->assertSame(50, $exact->context['length']);

        $longer = $this->validator->validate(str_repeat('a', 51), array('min' => 50));
        $this->assertTrue($longer->passed);
        $this->assertSame(51, $longer->context['length']);
    }
}
