<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain;

use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\Validation;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testTopLevelAndGroupDocumentsOmitQuantifier(): void
    {
        $top = RuleFactory::validation();
        $this->assertArrayNotHasKey('quantifier', $top->toArray());
        $this->assertFalse($top->isEveryRow());

        $group = RuleFactory::validation(array(
            'field' => new \ContentGuard\Domain\FieldRef(
                'field_ingredients',
                'ingredients',
                'Product Details → Ingredients',
                array('field_product_details', 'field_ingredients'),
                'group'
            ),
        ));
        $this->assertArrayNotHasKey('quantifier', $group->toArray());
        $this->assertFalse($group->isEveryRow());
        $this->assertSame(1, \ContentGuard\Domain\Rule::SCHEMA_VERSION);
    }

    public function testRepeaterChildDefaultsToEveryRow(): void
    {
        $validation = RuleFactory::validation(array(
            'field' => AcfRepeaterFixtures::productSizeRef(),
        ));
        $this->assertTrue($validation->isEveryRow());
        $this->assertArrayNotHasKey('quantifier', $validation->toArray());

        $explicit = RuleFactory::validation(array(
            'field' => AcfRepeaterFixtures::productSizeRef(),
            'quantifier' => Validation::QUANTIFIER_EVERY,
        ));
        $this->assertTrue($explicit->isEveryRow());
        $this->assertSame('every', $explicit->toArray()['quantifier']);

        $loaded = Validation::fromArray($explicit->toArray());
        $this->assertSame('every', $loaded->quantifier);
        $this->assertTrue($loaded->isEveryRow());
    }

    public function testFlexibleChildIsEveryInstanceButNotRepeaterEveryRow(): void
    {
        $validation = RuleFactory::validation(array(
            'field' => \ContentGuard\Tests\Support\AcfFlexibleFixtures::heroTitleRef(),
            'quantifier' => Validation::QUANTIFIER_EVERY,
        ));

        $this->assertFalse($validation->isEveryRow());
        $this->assertTrue($validation->isEveryInstance());
        $this->assertSame('every', $validation->toArray()['quantifier']);
        $this->assertSame(1, \ContentGuard\Domain\Rule::SCHEMA_VERSION);
    }

    public function testUnsupportedQuantifierIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Unsupported field quantifier.');
        RuleFactory::validation(array('quantifier' => 'any'));
    }
}
