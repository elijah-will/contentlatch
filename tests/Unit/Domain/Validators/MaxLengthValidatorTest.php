<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain\Validators;

use ContentGuard\Domain\Validators\MaxLengthValidator;
use PHPUnit\Framework\TestCase;

final class MaxLengthValidatorTest extends TestCase
{
    private MaxLengthValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MaxLengthValidator();
    }

    public function testPassesWhenWithinLimit(): void
    {
        $outcome = $this->validator->validate('ok', array('max' => 10));
        $this->assertTrue($outcome->passed);
        $this->assertSame(2, $outcome->context['length']);
    }

    public function testFailsWhenTooLong(): void
    {
        $outcome = $this->validator->validate('abcdefghijk', array('max' => 10));
        $this->assertFalse($outcome->passed);
        $this->assertSame('max_length', $outcome->code);
        $this->assertSame(11, $outcome->context['length']);
    }

    public function testEmptyPasses(): void
    {
        $outcome = $this->validator->validate('', array('max' => 10));
        $this->assertTrue($outcome->passed);
        $this->assertSame(0, $outcome->context['length']);
    }

    public function testFailsForNonScalar(): void
    {
        $outcome = $this->validator->validate(array('a'), array('max' => 10));
        $this->assertFalse($outcome->passed);
        $this->assertSame('invalid_type', $outcome->code);
    }

    public function testFailsWhenMaxMissing(): void
    {
        $outcome = $this->validator->validate('hello', array());
        $this->assertFalse($outcome->passed);
        $this->assertSame('invalid_params', $outcome->code);
    }

    public function testFiftyCharacterBoundary(): void
    {
        $shorter = $this->validator->validate(str_repeat('a', 49), array('max' => 50));
        $this->assertTrue($shorter->passed);
        $this->assertSame(49, $shorter->context['length']);

        $exact = $this->validator->validate(str_repeat('a', 50), array('max' => 50));
        $this->assertTrue($exact->passed);
        $this->assertSame(50, $exact->context['length']);

        $tooLong = $this->validator->validate(str_repeat('a', 51), array('max' => 50));
        $this->assertFalse($tooLong->passed);
        $this->assertSame('max_length', $tooLong->code);
        $this->assertSame(51, $tooLong->context['length']);
    }
}
