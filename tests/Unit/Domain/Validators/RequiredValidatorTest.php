<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain\Validators;

use ContentGuard\Domain\Validators\RequiredValidator;
use PHPUnit\Framework\TestCase;

final class RequiredValidatorTest extends TestCase
{
    private RequiredValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RequiredValidator();
    }

    public function testPassesWhenValuePresent(): void
    {
        $outcome = $this->validator->validate('tomatoes', array());
        $this->assertTrue($outcome->passed);
        $this->assertSame('required', $outcome->code);
    }

    /**
     * @dataProvider emptyProvider
     */
    public function testFailsWhenEmpty(mixed $value): void
    {
        $outcome = $this->validator->validate($value, array());
        $this->assertFalse($outcome->passed);
        $this->assertSame('required', $outcome->code);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function emptyProvider(): array
    {
        return array(
            'null'         => array(null),
            'false'        => array(false),
            'empty string' => array(''),
            'empty array'  => array(array()),
        );
    }

    public function testZeroIsNotEmpty(): void
    {
        $this->assertTrue($this->validator->validate(0, array())->passed);
        $this->assertTrue($this->validator->validate('0', array())->passed);
    }
}
