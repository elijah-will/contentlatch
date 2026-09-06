<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain\Validators;

use ContentGuard\Domain\Validators\AllowedValuesValidator;
use PHPUnit\Framework\TestCase;

final class AllowedValuesValidatorTest extends TestCase
{
    private AllowedValuesValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AllowedValuesValidator();
    }

    public function testPassesWhenValueIsAllowed(): void
    {
        $outcome = $this->validator->validate('sauce', array('values' => array('sauce', 'dip')));
        $this->assertTrue($outcome->passed);
        $this->assertSame('allowed_values', $outcome->code);
    }

    public function testFailsWhenValueIsNotAllowed(): void
    {
        $outcome = $this->validator->validate('soup', array('values' => array('sauce', 'dip')));
        $this->assertFalse($outcome->passed);
        $this->assertSame('soup', $outcome->context['actual']);
    }

    public function testComparisonIsCaseSensitive(): void
    {
        $outcome = $this->validator->validate('Sauce', array('values' => array('sauce')));
        $this->assertFalse($outcome->passed);
    }

    public function testEmptyFailsUnlessExplicitlyAllowed(): void
    {
        $denied = $this->validator->validate(null, array('values' => array('sauce')));
        $this->assertFalse($denied->passed);

        $allowed = $this->validator->validate('', array('values' => array('')));
        $this->assertTrue($allowed->passed);
    }

    public function testFailsWhenValuesMissing(): void
    {
        $outcome = $this->validator->validate('sauce', array());
        $this->assertFalse($outcome->passed);
        $this->assertSame('invalid_params', $outcome->code);
    }
}
