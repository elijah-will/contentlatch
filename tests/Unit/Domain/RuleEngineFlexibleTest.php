<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain;

use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\EvaluationStatus;
use ContentGuard\Domain\FieldInstance;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\Validation;
use ContentGuard\Tests\Support\AcfFlexibleFixtures;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleEngineFlexibleTest extends TestCase
{
    private RuleEngine $engine;

    protected function setUp(): void
    {
        $this->engine = RuleEngine::v1();
    }

    public function testHeroRuleIgnoresCtaRowAndFailsOnlyTheEmptyHero(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->heroTitleRule()),
            new ArrayValueProvider(array(
                AcfFlexibleFixtures::HERO_TITLE => array(
                    new FieldInstance('Welcome', $this->row(0, 'hero')),
                    new FieldInstance('', $this->row(2, 'hero')),
                ),
            )),
            5
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(1, $evaluation->results);
        $this->assertTrue($evaluation->results[0]->isFailed());
        $this->assertSame('required', $evaluation->results[0]->code);
        $this->assertSame(3, $evaluation->results[0]->context['display_row']);
        $this->assertSame('hero', $evaluation->results[0]->context['layout']);
    }

    public function testAllMatchingRowsPassOnce(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->heroTitleRule()),
            new ArrayValueProvider(array(
                AcfFlexibleFixtures::HERO_TITLE => array(
                    new FieldInstance('Welcome', $this->row(0, 'hero')),
                    new FieldInstance('About', $this->row(3, 'hero')),
                ),
            )),
            5
        );

        $this->assertTrue($evaluation->isPassed());
        $this->assertCount(1, $evaluation->results);
        $this->assertSame('required', $evaluation->results[0]->code);
    }

    public function testMultipleFailedHeroRowsAreReturned(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->heroTitleRule()),
            new ArrayValueProvider(array(
                AcfFlexibleFixtures::HERO_TITLE => array(
                    new FieldInstance('', $this->row(1, 'hero')),
                    new FieldInstance('', $this->row(4, 'hero')),
                ),
            )),
            5
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(2, $evaluation->results);
        $this->assertSame(array(2, 5), array_map(
            static fn ($result): int => (int) $result->context['display_row'],
            $evaluation->results
        ));
    }

    public function testZeroMatchingLayoutsProduceNoResult(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->heroTitleRule()),
            new ArrayValueProvider(array(
                AcfFlexibleFixtures::HERO_TITLE => array(),
            )),
            5
        );

        $this->assertSame(array(), $evaluation->results);
        $this->assertFalse($evaluation->isFailed());
        $this->assertSame(array(), array_filter(
            $evaluation->results,
            static fn ($result): bool => $result->code === 'no_rows'
        ));
    }

    public function testRepeaterZeroRowsStillProducesNoRows(): void
    {
        $evaluation = $this->engine->evaluate(
            array(RuleFactory::rule(array(
                'postType' => 'recipe',
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => AcfRepeaterFixtures::ingredientRef(),
                        'quantifier' => Validation::QUANTIFIER_EVERY,
                    )),
                ),
            ))),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::INGREDIENT => array(),
            )),
            12325
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame('no_rows', $evaluation->results[0]->code);
        $this->assertSame(EvaluationStatus::Failed, $evaluation->results[0]->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $index, string $layout): array
    {
        return array(
            'row_key'     => 'row-' . $index,
            'row_index'   => $index,
            'display_row' => $index + 1,
            'layout'      => $layout,
        );
    }

    private function heroTitleRule(): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(array(
            'postType' => 'page',
            'conditions' => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => AcfFlexibleFixtures::heroTitleRef(),
                    'quantifier' => Validation::QUANTIFIER_EVERY,
                )),
            ),
        ));
    }
}
