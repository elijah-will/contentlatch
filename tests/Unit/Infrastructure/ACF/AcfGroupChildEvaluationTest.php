<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfIncomingValueProvider;
use ContentGuard\Infrastructure\ACF\AcfStoredValueProvider;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AcfGroupChildEvaluationTest extends TestCase
{
    public function testStoredGroupTextareaPopulatedPassesRequired(): void
    {
        $evaluation = $this->evaluateStored(
            array(
                'field_type' => 'sauce',
                'field_ingredients' => '',
                'field_product_details' => array(
                    'field_ingredients' => 'Tomatoes, salt',
                ),
            )
        );

        $this->assertTrue($evaluation->isPassed());
    }

    public function testStoredGroupTextareaEmptyFailsRequired(): void
    {
        $evaluation = $this->evaluateStored(
            array(
                'field_type' => 'sauce',
                'field_ingredients' => '',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            )
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame('required', $evaluation->results[0]->code);
    }

    public function testStoredNestedGroupScalarStillWorks(): void
    {
        $rule = $this->caloriesRule();
        $evaluation = $this->evaluateStored(
            array(
                'field_calories' => '',
                'field_product_details' => array(
                    'field_nutrition' => array(
                        'field_calories' => '90',
                    ),
                ),
            ),
            array($rule),
            array(
                'field_calories' => 'number',
            ),
            array(
                'field_calories' => array('field_product_details', 'field_nutrition', 'field_calories'),
            )
        );

        $this->assertTrue($evaluation->isPassed());
    }

    public function testIncomingGroupTextareaPopulatedPassesRequired(): void
    {
        $evaluation = $this->evaluateIncoming(
            array(
                'field_type' => 'sauce',
                'field_product_details' => array(
                    'field_ingredients' => 'Tomatoes, salt',
                ),
            )
        );

        $this->assertTrue($evaluation->isPassed());
    }

    public function testIncomingGroupTextareaEmptyFailsRequired(): void
    {
        $evaluation = $this->evaluateIncoming(
            array(
                'field_type' => 'sauce',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            )
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame('required', $evaluation->results[0]->code);
    }

    public function testTopLevelScalarBehaviorRemainsUnchanged(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field('field_title', 'title', 'Title'),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );
        $fieldTypes = array('field_title' => 'text');
        $evaluator = new ContentEvaluator(
            new InMemoryRuleRepository(array($rule)),
            RuleEngine::v1()
        );
        $normalizer = new AcfValueNormalizer();

        $incomingEmpty = $evaluator->evaluate(
            9,
            'product',
            new AcfIncomingValueProvider(array('field_title' => ''), $normalizer, $fieldTypes)
        );
        $incomingFilled = $evaluator->evaluate(
            9,
            'product',
            new AcfIncomingValueProvider(array('field_title' => 'Hot Sauce'), $normalizer, $fieldTypes)
        );
        $storedEmpty = $evaluator->evaluate(
            9,
            'product',
            new AcfStoredValueProvider(
                9,
                $normalizer,
                $fieldTypes,
                static fn (string $key): mixed => $key === 'field_title' ? '' : null
            )
        );
        $storedFilled = $evaluator->evaluate(
            9,
            'product',
            new AcfStoredValueProvider(
                9,
                $normalizer,
                $fieldTypes,
                static fn (string $key): mixed => $key === 'field_title' ? 'Hot Sauce' : null
            )
        );

        $this->assertTrue($incomingEmpty->isFailed());
        $this->assertTrue($storedEmpty->isFailed());
        $this->assertTrue($incomingFilled->isPassed());
        $this->assertTrue($storedFilled->isPassed());
    }

    /**
     * @param array<string, mixed> $store
     * @param array<int, \ContentGuard\Domain\Rule> $rules
     * @param array<string, string> $fieldTypes
     * @param array<string, list<string>> $fieldPaths
     */
    private function evaluateStored(
        array $store,
        array $rules = array(),
        array $fieldTypes = array(),
        array $fieldPaths = array(),
    ): \ContentGuard\Domain\ContentEvaluation {
        $rules = $rules === array() ? array($this->sauceIngredientsRule()) : $rules;
        $fieldTypes = $fieldTypes === array()
            ? array(
                'field_type'        => 'select',
                'field_ingredients' => 'textarea',
            )
            : $fieldTypes;
        $fieldPaths = $fieldPaths === array()
            ? array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
            : $fieldPaths;

        $evaluator = new ContentEvaluator(
            new InMemoryRuleRepository($rules),
            RuleEngine::v1()
        );

        return $evaluator->evaluate(
            15,
            'product',
            new AcfStoredValueProvider(
                15,
                new AcfValueNormalizer(),
                $fieldTypes,
                static fn (string $key): mixed => $store[$key] ?? null,
                $fieldPaths
            )
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function evaluateIncoming(array $payload): \ContentGuard\Domain\ContentEvaluation
    {
        $evaluator = new ContentEvaluator(
            new InMemoryRuleRepository(array($this->sauceIngredientsRule())),
            RuleEngine::v1()
        );

        return $evaluator->evaluate(
            15,
            'product',
            new AcfIncomingValueProvider(
                $payload,
                new AcfValueNormalizer(),
                array(
                    'field_type'        => 'select',
                    'field_ingredients' => 'textarea',
                ),
                array(
                    'field_ingredients' => array('field_product_details', 'field_ingredients'),
                )
            )
        );
    }

    private function sauceIngredientsRule(): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(
            array(
                'conditions' => array(
                    RuleFactory::condition(
                        array(
                            'field'    => RuleFactory::field('field_type', 'product_type', 'Product Type'),
                            'operator' => 'equals',
                            'operand'  => 'sauce',
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => new FieldRef(
                                'field_ingredients',
                                'item_ingredients',
                                'Product Information → Ingredients Accordion',
                                array('field_product_details', 'field_ingredients'),
                                'group'
                            ),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );
    }

    private function caloriesRule(): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(
            array(
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => new FieldRef(
                                'field_calories',
                                'calories',
                                'Product Details → Nutrition → Calories',
                                array('field_product_details', 'field_nutrition', 'field_calories'),
                                'group'
                            ),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );
    }
}
