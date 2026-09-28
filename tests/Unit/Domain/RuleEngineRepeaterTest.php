<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Domain;

use ContentLatch\Domain\ArrayValueProvider;
use ContentLatch\Domain\EvaluationStatus;
use ContentLatch\Domain\FieldInstance;
use ContentLatch\Domain\FieldRef;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Tests\Support\AcfNestedRepeaterFixtures;
use ContentLatch\Tests\Support\AcfRepeaterFixtures;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleEngineRepeaterTest extends TestCase
{
    private RuleEngine $engine;

    protected function setUp(): void
    {
        $this->engine = RuleEngine::v1();
    }

    public function testEveryRowValidPassesOnce(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->ingredientRule()),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::INGREDIENT => array(
                    new FieldInstance('Salt', $this->row(0)),
                    new FieldInstance('Pepper', $this->row(1)),
                ),
            )),
            12325
        );

        $this->assertTrue($evaluation->isPassed());
        $this->assertCount(1, $evaluation->results);
        $this->assertTrue($evaluation->results[0]->isPassed());
        $this->assertSame('required', $evaluation->results[0]->code);
        $this->assertSame(AcfRepeaterFixtures::INGREDIENT, $evaluation->results[0]->fieldId);
    }

    public function testOneInvalidRowFailsThatInstance(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->ingredientRule()),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::INGREDIENT => array(
                    new FieldInstance('Salt', $this->row(0)),
                    new FieldInstance('', $this->row(1)),
                ),
            )),
            12325
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(1, $evaluation->results);
        $this->assertTrue($evaluation->results[0]->isFailed());
        $this->assertSame('required', $evaluation->results[0]->code);
        $this->assertSame(2, $evaluation->results[0]->context['display_row']);
        $this->assertSame('This field is required.', $evaluation->results[0]->message);
    }

    public function testMultipleInvalidRowsReturnEachFailedInstance(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->productSizeRule()),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::PRODUCT_TYPE => 'sauce',
                AcfRepeaterFixtures::PRODUCT_SIZE => array(
                    new FieldInstance('', $this->row(0)),
                    new FieldInstance('2.5oz', $this->row(1)),
                    new FieldInstance('', $this->row(2)),
                    new FieldInstance('8oz', $this->row(3)),
                    new FieldInstance('', $this->row(4)),
                ),
            )),
            636
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(3, $evaluation->results);
        $this->assertSame(array(1, 3, 5), array_map(
            static fn ($result): int => (int) $result->context['display_row'],
            $evaluation->results
        ));
        foreach ($evaluation->results as $result) {
            $this->assertSame('required', $result->code);
            $this->assertSame(AcfRepeaterFixtures::PRODUCT_SIZE, $result->fieldId);
        }
    }

    public function testZeroRowsIsNoRowsFailure(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->productSizeRule()),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::PRODUCT_TYPE => 'sauce',
                AcfRepeaterFixtures::PRODUCT_SIZE => array(),
            )),
            636
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(1, $evaluation->results);
        $this->assertSame('no_rows', $evaluation->results[0]->code);
        $this->assertSame('Add at least one Item Size row.', $evaluation->results[0]->message);
        $this->assertSame(EvaluationStatus::Failed, $evaluation->results[0]->status);
    }

    public function testZeroRowsWarningUsesNoRowsCode(): void
    {
        $rule = $this->productSizeRule(array('severity' => RuleSeverity::Warning));
        $evaluation = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::PRODUCT_TYPE => 'sauce',
                AcfRepeaterFixtures::PRODUCT_SIZE => array(),
            ))
        );

        $this->assertTrue($evaluation->isWarning());
        $this->assertFalse($evaluation->isFailed());
        $this->assertSame('no_rows', $evaluation->results[0]->code);
        $this->assertSame('Add at least one Item Size row.', $evaluation->results[0]->message);
    }

    public function testExistingValidatorsOperateOnEachScalarValue(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions' => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'  => AcfRepeaterFixtures::ingredientRef(),
                    'type'   => 'min_length',
                    'params' => array('min' => 5),
                    'quantifier' => 'every',
                )),
            ),
        ));

        $evaluation = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::INGREDIENT => array(
                    new FieldInstance('Salt', $this->row(0)),
                    new FieldInstance('Oil', $this->row(1)),
                    new FieldInstance('Pepper', $this->row(2)),
                ),
            ))
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(2, $evaluation->results);
        $this->assertSame('min_length', $evaluation->results[0]->code);
        $this->assertSame(array(1, 2), array_map(
            static fn ($result): int => (int) $result->context['display_row'],
            $evaluation->results
        ));
    }

    public function testEveryQuantifierValidatesEachNestedRepeaterInstance(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'      => AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));

        $evaluation = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array(
                AcfNestedRepeaterFixtures::STEP_NAME => array(
                    new FieldInstance('Cut', array('repeater_rows' => array(
                        array('display_row' => 1),
                        array('display_row' => 1),
                    ))),
                    new FieldInstance('', array('repeater_rows' => array(
                        array('display_row' => 1),
                        array('display_row' => 2),
                    ))),
                    new FieldInstance('Grill', array('repeater_rows' => array(
                        array('display_row' => 2),
                        array('display_row' => 1),
                    ))),
                    new FieldInstance('', array('repeater_rows' => array(
                        array('display_row' => 2),
                        array('display_row' => 2),
                    ))),
                ),
            ))
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(2, $evaluation->results);
        $this->assertSame('required', $evaluation->results[0]->code);
        $this->assertSame('required', $evaluation->results[1]->code);
        $this->assertSame(AcfNestedRepeaterFixtures::STEP_NAME, $evaluation->results[0]->fieldId);
        $this->assertSame(2, $evaluation->results[0]->context['repeater_rows'][1]['display_row']);
        $this->assertSame(2, $evaluation->results[1]->context['repeater_rows'][0]['display_row']);
        $this->assertSame('every', $rule->validations[0]->quantifier);
    }

    public function testConditionsRemainUnchangedAndSkipRepeaterThen(): void
    {
        $evaluation = $this->engine->evaluate(
            array($this->productSizeRule()),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::PRODUCT_TYPE => 'dip',
                AcfRepeaterFixtures::PRODUCT_SIZE => array(),
            ))
        );

        $this->assertTrue($evaluation->isNotEvaluated());
        $this->assertSame('conditions_not_met', $evaluation->results[0]->code);
    }

    public function testTopLevelAndGroupRulesUnchanged(): void
    {
        $group = new FieldRef(
            'field_ingredients',
            'ingredients',
            'Product Details → Ingredients',
            array('field_product_details', 'field_ingredients'),
            'group'
        );
        $rules = array(
            RuleFactory::rule(array(
                'id' => 1,
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => RuleFactory::field('field_title', 'title', 'Title'),
                        'type'  => 'required',
                    )),
                ),
            )),
            RuleFactory::rule(array(
                'id' => 2,
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => $group,
                        'type'  => 'required',
                    )),
                ),
            )),
        );

        $pass = $this->engine->evaluate(
            $rules,
            new ArrayValueProvider(array(
                'field_title' => 'Name',
                'field_ingredients' => 'Salt',
            ))
        );
        $this->assertTrue($pass->isPassed());
        $this->assertCount(2, $pass->results);

        $fail = $this->engine->evaluate(
            $rules,
            new ArrayValueProvider(array(
                'field_title' => '',
                'field_ingredients' => '',
            ))
        );
        $this->assertTrue($fail->isFailed());
        $this->assertSame('required', $fail->results[0]->code);
        $this->assertSame('required', $fail->results[1]->code);
        $this->assertArrayNotHasKey('display_row', $fail->results[0]->context);
    }

    public function testWhenContainsMatchesAnyRepeaterRow(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field'    => AcfRepeaterFixtures::ingredientRef(),
                    'operator' => 'contains',
                    'operand'  => 'chicken',
                )),
            ),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => RuleFactory::field('title', 'post_title', 'Title'),
                )),
            ),
        ));

        $this->assertTrue(
            $this->engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    AcfRepeaterFixtures::INGREDIENT => array(
                        new FieldInstance('Salt', $this->row(0)),
                        new FieldInstance('Chicken stock', $this->row(1)),
                    ),
                    'title' => 'Recipe',
                ))
            )->isPassed()
        );
        $this->assertTrue(
            $this->engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    AcfRepeaterFixtures::INGREDIENT => array(
                        new FieldInstance('Salt', $this->row(0)),
                        new FieldInstance('Pepper', $this->row(1)),
                    ),
                    'title' => 'Recipe',
                ))
            )->results[0]->isSkipped()
        );
        $this->assertTrue(
            $this->engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    AcfRepeaterFixtures::INGREDIENT => array(),
                    'title' => 'Recipe',
                ))
            )->results[0]->isSkipped()
        );
    }

    public function testConditionOnlyWhenContainsMatchesAnyRepeaterRow(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => AcfRepeaterFixtures::ingredientRef(),
                    'operator' => 'contains',
                    'operand'  => 'chicken',
                )),
            ),
            'validations' => array(),
            'message'     => 'Do not use chicken in this repeater.',
        ));

        $matched = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array(
                AcfRepeaterFixtures::INGREDIENT => array(
                    new FieldInstance('Salt', $this->row(0)),
                    new FieldInstance('Chicken stock', $this->row(1)),
                ),
            ))
        );
        $this->assertTrue($matched->isFailed());
        $this->assertSame('condition_matched', $matched->results[0]->code);
        $this->assertSame(AcfRepeaterFixtures::INGREDIENT, $matched->results[0]->fieldId);
        $this->assertSame(1, $matched->results[0]->context['row_index']);
        $this->assertSame('Do not use chicken in this repeater.', $matched->results[0]->message);

        $this->assertTrue(
            $this->engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    AcfRepeaterFixtures::INGREDIENT => array(
                        new FieldInstance('Salt', $this->row(0)),
                        new FieldInstance('Pepper', $this->row(1)),
                    ),
                ))
            )->results[0]->isPassed()
        );
    }

    public function testConditionOnlyNestedRepeaterWhenStillMatchesAnyRow(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => AcfNestedRepeaterFixtures::stepNameRef(),
                    'operator' => 'contains',
                    'operand'  => 'chicken',
                )),
            ),
            'validations' => array(),
            'message'     => 'Avoid chicken in nested steps.',
        ));

        $matched = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array(
                AcfNestedRepeaterFixtures::STEP_NAME => array(
                    new FieldInstance('Cut vegetables', array('repeater_rows' => array(
                        array('display_row' => 1),
                        array('display_row' => 1),
                    ))),
                    new FieldInstance('Add chicken', array('repeater_rows' => array(
                        array('display_row' => 1),
                        array('display_row' => 2),
                    ))),
                ),
            ))
        );
        $this->assertTrue($matched->isFailed());
        $this->assertSame(AcfNestedRepeaterFixtures::STEP_NAME, $matched->results[0]->fieldId);
        $this->assertSame(2, $matched->results[0]->context['repeater_rows'][1]['display_row']);

        $this->assertTrue(
            $this->engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    AcfNestedRepeaterFixtures::STEP_NAME => array(
                        new FieldInstance('Cut vegetables', array('repeater_rows' => array(
                            array('display_row' => 1),
                            array('display_row' => 1),
                        ))),
                    ),
                ))
            )->results[0]->isPassed()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function ingredientRule(array $overrides = array()): \ContentLatch\Domain\Rule
    {
        return RuleFactory::rule(array_merge(array(
            'id' => 40,
            'postType' => 'recipe',
            'conditions' => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => AcfRepeaterFixtures::ingredientRef(),
                    'type'  => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ), $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function productSizeRule(array $overrides = array()): \ContentLatch\Domain\Rule
    {
        return RuleFactory::rule(array_merge(array(
            'id' => 41,
            'postType' => 'product',
            'conditions' => array(
                RuleFactory::condition(array(
                    'field' => RuleFactory::field(
                        AcfRepeaterFixtures::PRODUCT_TYPE,
                        'product_type',
                        'Product Type'
                    ),
                    'operator' => 'equals',
                    'operand'  => 'sauce',
                )),
            ),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => AcfRepeaterFixtures::productSizeRef(),
                    'type'  => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ), $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $index): array
    {
        return array(
            'row_key'     => 'row-' . $index,
            'row_index'   => $index,
            'display_row' => $index + 1,
            'input_name'  => 'acf[field][row-' . $index . '][child]',
        );
    }
}
