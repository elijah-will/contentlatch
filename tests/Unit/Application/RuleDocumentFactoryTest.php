<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Application\RulePresentation;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleDocumentFactoryTest extends TestCase
{
    private RuleDocumentFactory $factory;

    protected function setUp(): void
    {
        $this->factory = $this->factory();
    }

    public function testValidRuleWithConditionsAndMultipleValidations(): void
    {
        $rule = $this->factory->fromAdminInput($this->validInput());

        $this->assertSame('Sauce Products Must Have Ingredients', $rule->name);
        $this->assertSame('product', $rule->postType);
        $this->assertSame(RuleSeverity::Fail, $rule->severity);
        $this->assertSame(RuleStatus::Active, $rule->status);
        $this->assertCount(1, $rule->conditions);
        $this->assertSame('equals', $rule->conditions[0]->operator);
        $this->assertSame('sauce', $rule->conditions[0]->operand);
        $this->assertSame('field_type', $rule->conditions[0]->field->key);
        $this->assertCount(2, $rule->validations);
        $this->assertSame('required', $rule->validations[0]->type);
        $this->assertSame('min_length', $rule->validations[1]->type);
        $this->assertSame(3, $rule->validations[1]->params['min']);
        $this->assertSame('Ingredients are required for sauce products.', $rule->validations[0]->message);
        $this->assertSame('Ingredients are required for sauce products.', $rule->message);
    }

    public function testWarningAlwaysAppliesRule(): void
    {
        $input = $this->validInput();
        $input['severity'] = 'warning';
        $input['conditions'] = array();
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'required',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);

        $this->assertSame(RuleSeverity::Warning, $rule->severity);
        $this->assertSame(array(), $rule->conditions);
        $this->assertSame('Always applies', RulePresentation::conditionsSummary($rule));
        $this->assertSame('Ingredients is required', RulePresentation::validationsSummary($rule));
    }

    public function testEditPreservesRuleAndConditionIds(): void
    {
        $input = $this->validInput();
        $input['id'] = '652';
        $input['conditions'][0]['id'] = 'c-keep';
        $input['validations'][0]['id'] = 'v-keep';

        $rule = $this->factory->fromAdminInput($input);

        $this->assertSame(652, $rule->id);
        $this->assertSame('c-keep', $rule->conditions[0]->id);
        $this->assertSame('v-keep', $rule->validations[0]->id);
    }

    public function testAllowedValuesAndMaxLength(): void
    {
        $input = $this->validInput();
        $input['validations'] = array(
            array(
                'field_key' => 'field_type',
                'type'      => 'allowed_values',
                'values'    => 'sauce, rub',
            ),
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'max_length',
                'max'       => '40',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);

        $this->assertSame(array('sauce', 'rub'), $rule->validations[0]->params['values']);
        $this->assertSame(40, $rule->validations[1]->params['max']);
    }

    public function testMissingNameIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Rule name is required.');
        $input = $this->validInput();
        $input['name'] = '  ';
        $this->factory->fromAdminInput($input);
    }

    public function testInvalidPostTypeIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Unsupported post type.');
        $input = $this->validInput();
        $input['post_type'] = 'not-a-type';
        $this->factory->fromAdminInput($input);
    }

    public function testUnsupportedFieldIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Unsupported field.');
        $input = $this->validInput();
        $input['conditions'][0]['field_key'] = 'field_not_in_catalog';
        $this->factory->fromAdminInput($input);
    }

    public function testInvalidOperatorIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('starts_with');
        $input = $this->validInput();
        $input['conditions'][0]['operator'] = 'starts_with';
        $this->factory->fromAdminInput($input);
    }

    public function testMissingConditionValueIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('A condition value is required.');
        $input = $this->validInput();
        $input['conditions'][0]['operand'] = '';
        $this->factory->fromAdminInput($input);
    }

    public function testInvalidValidatorIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('regex');
        $input = $this->validInput();
        $input['validations'][0]['type'] = 'regex';
        $this->factory->fromAdminInput($input);
    }

    public function testInvalidSeverityIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Invalid rule severity.');
        $input = $this->validInput();
        $input['severity'] = 'error';
        $this->factory->fromAdminInput($input);
    }

    public function testConditionOnlyBlockingRulePersists(): void
    {
        $input = $this->validInput();
        $input['name'] = 'Avoid healthy';
        $input['message'] = 'Please avoid the term "healthy" in recipe content.';
        $input['conditions'] = array(
            array(
                'field_key' => 'field_ingredients',
                'operator'  => 'contains',
                'operand'   => 'healthy',
            ),
        );
        $input['validations'] = array(
            array(
                'field_key' => '',
                'type'      => 'required',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);

        $this->assertSame(array(), $rule->validations);
        $this->assertCount(1, $rule->conditions);
        $this->assertSame('contains', $rule->conditions[0]->operator);
        $this->assertSame('healthy', $rule->conditions[0]->operand);
        $this->assertSame('Please avoid the term "healthy" in recipe content.', $rule->message);
        $this->assertSame(RuleSeverity::Fail, $rule->severity);
        $this->assertSame('None', RulePresentation::validationsSummary($rule));
        $this->assertSame(1, $rule->schemaVersion);

        $loaded = RuleDocumentValidator::v1()->validateArray($rule->toArray());
        $this->assertSame($rule->message, $loaded->message);
        $this->assertSame(array(), $loaded->validations);
    }

    public function testConditionOnlyWarningRulePersists(): void
    {
        $input = $this->validInput();
        $input['name'] = 'Warn on healthy';
        $input['severity'] = 'warning';
        $input['message'] = 'Please avoid the term "healthy" in recipe content.';
        $input['conditions'] = array(
            array(
                'field_key' => 'field_ingredients',
                'operator'  => 'contains',
                'operand'   => 'healthy',
            ),
        );
        $input['validations'] = array();

        $rule = $this->factory->fromAdminInput($input);

        $this->assertSame(RuleSeverity::Warning, $rule->severity);
        $this->assertSame(array(), $rule->validations);
        $this->assertSame('Please avoid the term "healthy" in recipe content.', $rule->message);
    }

    public function testCompletelyEmptyRuleIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_MISSING_WHEN_OR_THEN);
        $input = $this->validInput();
        $input['conditions'] = array();
        $input['validations'] = array(
            array(
                'field_key' => '',
                'type'      => 'required',
            ),
        );
        $this->factory->fromAdminInput($input);
    }

    public function testMinLengthFiftyPersistsAsIntegerAndEvaluatesBoundaries(): void
    {
        $input = $this->validInput();
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'min_length',
                'min'       => '50',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame(50, $rule->validations[0]->params['min']);
        $this->assertIsInt($rule->validations[0]->params['min']);

        $engine = RuleEngine::v1();
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => str_repeat('a', 10),
                ))
            )->isFailed()
        );
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => str_repeat('a', 50),
                ))
            )->isPassed()
        );
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => str_repeat('a', 51),
                ))
            )->isPassed()
        );
    }

    public function testMaxLengthFiftyPersistsAsIntegerAndEvaluatesBoundaries(): void
    {
        $input = $this->validInput();
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'max_length',
                'max'       => '50',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame(50, $rule->validations[0]->params['max']);
        $this->assertIsInt($rule->validations[0]->params['max']);

        $engine = RuleEngine::v1();
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => str_repeat('a', 49),
                ))
            )->isPassed()
        );
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => str_repeat('a', 50),
                ))
            )->isPassed()
        );
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => str_repeat('a', 51),
                ))
            )->isFailed()
        );
    }

    public function testTrueFalseEqualsZeroAndOneAreValid(): void
    {
        $yes = $this->validInput();
        $yes['name'] = 'Repair UPC is required';
        $yes['conditions'] = array();
        $yes['validations'] = array(
            array(
                'field_key' => 'field_repair_upc',
                'type'      => 'required',
            ),
        );

        $created = $this->factory->fromAdminInput($yes);
        $this->assertSame('field_repair_upc', $created->validations[0]->field->key);
        $this->assertSame('required', $created->validations[0]->type);

        $whenNo = $this->validInput();
        $whenNo['conditions'] = array(
            array(
                'field_key' => 'field_repair_upc',
                'operator'  => 'equals',
                'operand'   => '0',
            ),
        );
        $whenNo['validations'] = array(
            array(
                'field_key' => 'field_page_id',
                'type'      => 'required',
            ),
        );

        $conditional = $this->factory->fromAdminInput($whenNo);
        $this->assertSame('0', $conditional->conditions[0]->operand);
        $this->assertSame('field_page_id', $conditional->validations[0]->field->key);
    }

    public function testShowNewTagYesRequiresPageId(): void
    {
        $rule = $this->factory->fromAdminInput($this->showNewTagInput('1'));

        $this->assertSame('1', $rule->conditions[0]->operand);
        $this->assertSame('field_show_new_tag', $rule->conditions[0]->field->key);
        $this->assertSame('field_page_id', $rule->validations[0]->field->key);
        $this->assertSame('required', $rule->validations[0]->type);
    }

    public function testShowNewTagNoRequiresPageId(): void
    {
        $rule = $this->factory->fromAdminInput($this->showNewTagInput('0'));

        $this->assertSame('0', $rule->conditions[0]->operand);
        $this->assertSame('field_show_new_tag', $rule->conditions[0]->field->key);
        $this->assertSame('field_page_id', $rule->validations[0]->field->key);
        $this->assertSame('required', $rule->validations[0]->type);
    }

    public function testTrueFalseYesNoLabelsNormalizeToBits(): void
    {
        $yes = $this->factory->fromAdminInput($this->showNewTagInput('Yes'));
        $no  = $this->factory->fromAdminInput($this->showNewTagInput('No'));

        $this->assertSame('1', $yes->conditions[0]->operand);
        $this->assertSame('0', $no->conditions[0]->operand);
    }

    public function testTargetPostTypeAliasIsAccepted(): void
    {
        $input = $this->showNewTagInput('1');
        $input['target_post_type'] = 'product';
        unset($input['post_type']);

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame('product', $rule->postType);
    }

    public function testRuleIdAliasIsAcceptedOnCreateInput(): void
    {
        $input = $this->validInput();
        $input['rule_id'] = '12';
        unset($input['id']);

        $this->assertSame(12, $this->factory->fromAdminInput($input)->id);
    }

    public function testEmptyPlusSameFieldRequiredIsRejected(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array(
            array(
                'field_key' => 'field_page_id',
                'operator'  => 'is_empty',
            ),
        );
        $input['validations'] = array(
            array(
                'field_key' => 'field_page_id',
                'type'      => 'required',
            ),
        );

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_EMPTY_AND_REQUIRED);
        $this->factory->fromAdminInput($input);
    }

    public function testNotEmptyPlusSameFieldRequiredIsRejected(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array(
            array(
                'field_key' => 'field_page_id',
                'operator'  => 'is_not_empty',
            ),
        );
        $input['validations'] = array(
            array(
                'field_key' => 'field_page_id',
                'type'      => 'required',
            ),
        );

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_NOT_EMPTY_AND_REQUIRED);
        $this->factory->fromAdminInput($input);
    }

    public function testEqualsPlusSameFieldRequiredIsRejected(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array(
            array(
                'field_key' => 'field_page_id',
                'operator'  => 'equals',
                'operand'   => '123',
            ),
        );
        $input['validations'] = array(
            array(
                'field_key' => 'field_page_id',
                'type'      => 'required',
            ),
        );

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_EQUALS_AND_REQUIRED);
        $this->factory->fromAdminInput($input);
    }

    public function testMinGreaterThanMaxIsRejected(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array();
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'min_length',
                'min'       => '50',
            ),
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'max_length',
                'max'       => '10',
            ),
        );

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_MIN_GT_MAX);
        $this->factory->fromAdminInput($input);
    }

    public function testRequiredPlusMinLengthIsValid(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array();
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'required',
            ),
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'min_length',
                'min'       => '50',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame('required', $rule->validations[0]->type);
        $this->assertSame('min_length', $rule->validations[1]->type);
        $this->assertSame(50, $rule->validations[1]->params['min']);
    }

    public function testMissingThenFieldIsRejected(): void
    {
        $input = $this->validInput();
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => '',
            ),
        );

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_MISSING_THEN);
        $this->factory->fromAdminInput($input);
    }

    public function testRequiredIgnoresStaleLengthAndAllowedValuesParams(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array();
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'required',
                'min'       => '50',
                'max'       => '10',
                'values'    => 'sauce, rub',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);

        $this->assertSame('required', $rule->validations[0]->type);
        $this->assertSame(array(), $rule->validations[0]->params);
    }

    public function testNumericComparisonOnNumberFieldPersistsAndEvaluates(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array(
            array(
                'field_key' => 'field_cook_time',
                'operator'  => 'greater_than',
                'operand'   => '30',
            ),
        );
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'required',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);

        $this->assertSame('greater_than', $rule->conditions[0]->operator);
        $this->assertSame('30', $rule->conditions[0]->operand);
        $this->assertSame(1, $rule->schemaVersion);

        $engine = RuleEngine::v1();
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_cook_time'   => 50,
                    'field_ingredients' => 'tomatoes',
                ))
            )->isPassed()
        );
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_cook_time'   => 5,
                    'field_ingredients' => '',
                ))
            )->isNotEvaluated()
        );
    }

    public function testNumericComparisonOnTextFieldIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Numeric comparisons can only be used with number fields.');
        $input = $this->validInput();
        $input['conditions'][0]['operator'] = 'greater_than';
        $input['conditions'][0]['operand'] = '30';
        $this->factory->fromAdminInput($input);
    }

    public function testInvalidNumericOperandIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('A numeric condition value is required.');
        $input = $this->validInput();
        $input['conditions'] = array(
            array(
                'field_key' => 'field_cook_time',
                'operator'  => 'less_than',
                'operand'   => 'thirty',
            ),
        );
        $this->factory->fromAdminInput($input);
    }

    public function testZeroIsAValidNumericOperand(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array(
            array(
                'field_key' => 'field_cook_time',
                'operator'  => 'greater_than',
                'operand'   => '0',
            ),
        );
        $input['validations'] = array(
            array(
                'field_key' => 'field_ingredients',
                'type'      => 'required',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame('0', $rule->conditions[0]->operand);
    }

    public function testExistingTextOperatorsRemainAvailable(): void
    {
        $input = $this->validInput();
        $input['conditions'][0]['operator'] = 'not_equals';
        $input['conditions'][0]['operand'] = 'dip';

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame('not_equals', $rule->conditions[0]->operator);
        $this->assertSame('dip', $rule->conditions[0]->operand);
    }

    public function testContainsConditionPersistsAndEvaluatesThroughRuleEngine(): void
    {
        $input = $this->validInput();
        $input['name'] = 'Avoid healthy';
        $input['conditions'] = array(
            array(
                'field_key' => 'field_ingredients',
                'operator'  => 'contains',
                'operand'   => 'healthy',
            ),
        );
        $input['validations'] = array(
            array(
                'field_key' => 'field_page_id',
                'type'      => 'required',
                'message'   => 'Please avoid the term "healthy".',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame('contains', $rule->conditions[0]->operator);
        $this->assertSame('healthy', $rule->conditions[0]->operand);
        $this->assertSame('field_ingredients', $rule->conditions[0]->field->key);

        $engine = RuleEngine::v1();
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_ingredients' => 'This is a HEALTHY recipe.',
                    'field_page_id'     => '',
                ))
            )->isFailed()
        );
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array(
                    'field_ingredients' => 'This recipe is nutritious.',
                    'field_page_id'     => '',
                ))
            )->isNotEvaluated()
        );
    }

    public function testDoesNotContainConditionPersists(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array(
            array(
                'field_key' => 'field_page_id',
                'operator'  => 'does_not_contain',
                'operand'   => 'B&G',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame('does_not_contain', $rule->conditions[0]->operator);
        $this->assertSame('B&G', $rule->conditions[0]->operand);
    }

    public function testEmptyContainsValueIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('A condition value is required.');
        $input = $this->validInput();
        $input['conditions'][0]['field_key'] = 'field_ingredients';
        $input['conditions'][0]['operator'] = 'contains';
        $input['conditions'][0]['operand'] = '';
        $this->factory->fromAdminInput($input);
    }

    public function testContainsOnNumberFieldIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Contains conditions can only be used with text fields.');
        $input = $this->validInput();
        $input['conditions'][0]['field_key'] = 'field_cook_time';
        $input['conditions'][0]['operator'] = 'contains';
        $input['conditions'][0]['operand'] = '30';
        $this->factory->fromAdminInput($input);
    }

    public function testContainsOnTrueFalseFieldIsRejectedEvenWithForgedType(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Contains conditions can only be used with text fields.');
        $input = $this->validInput();
        $input['conditions'][0]['field_key'] = 'field_show_new_tag';
        $input['conditions'][0]['operator'] = 'contains';
        $input['conditions'][0]['operand'] = 'Yes';
        $input['conditions'][0]['type'] = 'text';
        $this->factory->fromAdminInput($input);
    }

    public function testEmptyOperatorDoesNotNeedAValue(): void
    {
        $input = $this->validInput();
        $input['conditions'] = array(
            array(
                'field_key' => 'field_show_new_tag',
                'operator'  => 'is_empty',
                'operand'   => '',
            ),
        );

        $rule = $this->factory->fromAdminInput($input);
        $this->assertSame('is_empty', $rule->conditions[0]->operator);
        $this->assertNull($rule->conditions[0]->operand);
    }

    /**
     * @return array<string, mixed>
     */
    private function showNewTagInput(string $operand): array
    {
        $input = $this->validInput();
        $input['name'] = 'Page ID is required when Show New Tag is Yes';
        $input['conditions'] = array(
            array(
                'field_key' => 'field_show_new_tag',
                'operator'  => 'equals',
                'operand'   => $operand,
            ),
        );
        $input['validations'] = array(
            array(
                'field_key' => 'field_page_id',
                'type'      => 'required',
            ),
        );

        return $input;
    }

    public function testNestedFieldRefPersistsPathAndBreadcrumbLabel(): void
    {
        $factory = $this->nestedFactory();
        $rule = $factory->fromAdminInput(array(
            'name'       => 'Sauce Products Must Have Ingredients',
            'post_type'  => 'product',
            'status'     => 'active',
            'severity'   => 'fail',
            'conditions' => array(
                array(
                    'field_key' => 'field_type',
                    'operator'  => 'equals',
                    'operand'   => 'sauce',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => 'field_ingredients',
                    'type'      => 'required',
                ),
            ),
        ));

        $field = $rule->validations[0]->field;
        $this->assertSame('field_ingredients', $field->key);
        $this->assertSame('ingredients', $field->name);
        $this->assertSame('Product Details → Ingredients', $field->label);
        $this->assertSame(array('field_product_details', 'field_ingredients'), $field->path);
        $this->assertSame('group', $field->container);

        $serialized = $field->toArray();
        $this->assertSame(array('field_product_details', 'field_ingredients'), $serialized['path']);
        $this->assertSame('group', $serialized['container']);

        $loaded = RuleDocumentValidator::v1()->validateArray($rule->toArray());
        $this->assertSame($field->path, $loaded->validations[0]->field->path);
        $this->assertSame('Product Details → Ingredients is required', RulePresentation::validationsSummary($loaded));
    }

    public function testInvalidCatalogPathIsRejected(): void
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            static fn (): array => array(
                array(
                    'key'       => 'field_ingredients',
                    'name'      => 'ingredients',
                    'label'     => 'Ingredients',
                    'type'      => 'textarea',
                    'path'      => array('field_group.field_child', 'field_ingredients'),
                    'container' => 'group',
                ),
            )
        );

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Invalid field path.');
        $factory->fromAdminInput(array(
            'name'        => 'Bad path',
            'post_type'   => 'product',
            'validations' => array(
                array(
                    'field_key' => 'field_ingredients',
                    'type'      => 'required',
                ),
            ),
        ));
    }

    public function testExistingTopLevelDocumentLoadsWithoutOptionalMetadata(): void
    {
        $rule = $this->factory->fromAdminInput($this->validInput());
        $field = $rule->validations[0]->field;

        $this->assertSame('field_ingredients', $field->key);
        $this->assertSame('Ingredients', $field->label);
        $this->assertSame(array(), $field->path);
        $this->assertSame('', $field->container);
        $this->assertArrayNotHasKey('path', $field->toArray());
        $this->assertArrayNotHasKey('container', $field->toArray());

        $loaded = RuleDocumentValidator::v1()->validateArray($rule->toArray());
        $this->assertSame(array(), $loaded->validations[0]->field->path);
        $this->assertArrayNotHasKey('path', $loaded->validations[0]->field->toArray());
    }

    public function testRepeaterChildValidationPersistsEveryQuantifierAndSchemaVersionOne(): void
    {
        $factory = $this->repeaterFactory();
        $rule = $factory->fromAdminInput(array(
            'name'       => 'Sauce sizes required',
            'post_type'  => 'product',
            'status'     => 'active',
            'severity'   => 'fail',
            'conditions' => array(
                array(
                    'field_key' => \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE,
                    'operator'  => 'equals',
                    'operand'   => 'sauce',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE,
                    'type'      => 'required',
                ),
            ),
        ));

        $field = $rule->validations[0]->field;
        $this->assertSame(1, $rule->schemaVersion);
        $this->assertSame(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE, $field->key);
        $this->assertSame('repeater', $field->container);
        $this->assertSame('Product Information → Item Size → Product Size', $field->label);
        $this->assertSame('every', $rule->validations[0]->quantifier);
        $this->assertTrue($rule->validations[0]->isEveryRow());
        $this->assertArrayNotHasKey('any', $rule->validations[0]->toArray());

        $loaded = RuleDocumentValidator::v1()->validateArray($rule->toArray());
        $this->assertSame(1, $loaded->schemaVersion);
        $this->assertSame('every', $loaded->validations[0]->quantifier);
        $this->assertSame('repeater', $loaded->validations[0]->field->container);
    }

    public function testFlexibleChildValidationPersistsLayoutAndEveryQuantifier(): void
    {
        $factory = $this->flexibleFactory();
        $rule = $factory->fromAdminInput(array(
            'name'       => 'Hero title required',
            'post_type'  => 'page',
            'status'     => 'active',
            'severity'   => 'fail',
            'validations' => array(
                array(
                    'field_key' => \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                    'type'      => 'required',
                ),
            ),
        ));

        $field = $rule->validations[0]->field;
        $this->assertSame(1, $rule->schemaVersion);
        $this->assertSame('flexible_content', $field->container);
        $this->assertSame('hero', $field->layout);
        $this->assertSame('Modules → Hero → Title', $field->label);
        $this->assertSame(
            array(
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::MODULES,
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
            ),
            $field->path
        );
        $this->assertSame('every', $rule->validations[0]->quantifier);
        $this->assertTrue($rule->validations[0]->isEveryInstance());
        $this->assertFalse($rule->validations[0]->isEveryRow());

        $loaded = RuleDocumentValidator::v1()->validateArray($rule->toArray());
        $this->assertSame(1, $loaded->schemaVersion);
        $this->assertSame('hero', $loaded->validations[0]->field->layout);
        $this->assertArrayNotHasKey('layout', RuleFactory::validation()->field->toArray());
    }

    public function testFlexibleChildCanBeUsedAsWhenCondition(): void
    {
        $factory = $this->flexibleFactory();
        $rule = $factory->fromAdminInput(array(
            'name'       => 'Hero title when',
            'post_type'  => 'page',
            'validations' => array(
                array(
                    'field_key' => \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                    'type'      => 'required',
                ),
            ),
            'conditions' => array(
                array(
                    'field_key' => \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                    'operator'  => 'contains',
                    'operand'   => 'healthy',
                ),
            ),
        ));

        $this->assertSame('contains', $rule->conditions[0]->operator);
        $this->assertSame('healthy', $rule->conditions[0]->operand);
        $this->assertSame(\ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE, $rule->conditions[0]->field->key);
        $this->assertSame('flexible_content', $rule->conditions[0]->field->container);
    }

    public function testRepeaterChildCanBeUsedAsWhenCondition(): void
    {
        $factory = $this->repeaterFactory();
        $rule = $factory->fromAdminInput(array(
            'name'       => 'Size when',
            'post_type'  => 'product',
            'validations' => array(
                array(
                    'field_key' => \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE,
                    'type'      => 'required',
                ),
            ),
            'conditions' => array(
                array(
                    'field_key' => \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE,
                    'operator'  => 'equals',
                    'operand'   => '8oz',
                ),
            ),
        ));

        $this->assertSame('equals', $rule->conditions[0]->operator);
        $this->assertSame('8oz', $rule->conditions[0]->operand);
        $this->assertSame(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE, $rule->conditions[0]->field->key);
        $this->assertSame('repeater', $rule->conditions[0]->field->container);
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(): array
    {
        return array(
            'name'       => 'Sauce Products Must Have Ingredients',
            'post_type'  => 'product',
            'status'     => 'active',
            'severity'   => 'fail',
            'message'    => 'Ingredients are required for sauce products.',
            'conditions' => array(
                array(
                    'field_key' => 'field_type',
                    'operator'  => 'equals',
                    'operand'   => 'sauce',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => 'field_ingredients',
                    'type'      => 'required',
                ),
                array(
                    'field_key' => 'field_ingredients',
                    'type'      => 'min_length',
                    'min'       => '3',
                ),
            ),
        );
    }

    private function flexibleFactory(): RuleDocumentFactory
    {
        $catalog = \ContentGuard\Tests\Support\AcfFlexibleFixtures::pageCatalog();

        return RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page'),
            static function (string $postType) use ($catalog): array {
                $fields = array();
                foreach ($catalog->fieldsForPostType($postType) as $field) {
                    $fields[] = $field->toCatalogArray();
                }

                return $fields;
            }
        );
    }

    private function repeaterFactory(): RuleDocumentFactory
    {
        $catalog = \ContentGuard\Tests\Support\AcfRepeaterFixtures::productCatalog();

        return RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            static function (string $postType) use ($catalog): array {
                $fields = array();
                foreach ($catalog->fieldsForPostType($postType) as $field) {
                    $fields[] = $field->toCatalogArray();
                }

                return $fields;
            }
        );
    }

    private function nestedFactory(): RuleDocumentFactory
    {
        return RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            static fn (string $postType): array => $postType === 'product'
                ? array(
                    array(
                        'key'   => 'field_type',
                        'name'  => 'product_type',
                        'label' => 'Product Type',
                        'type'  => 'select',
                    ),
                    array(
                        'key'        => 'field_ingredients',
                        'name'       => 'ingredients',
                        'label'      => 'Ingredients',
                        'type'       => 'textarea',
                        'path'       => array('field_product_details', 'field_ingredients'),
                        'container'  => 'group',
                        'breadcrumb' => 'Product Details → Ingredients',
                    ),
                )
                : array()
        );
    }

    private function factory(): RuleDocumentFactory
    {
        return RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product', 'recipe' => 'Recipe'),
            static fn (string $postType): array => $postType === 'product' || $postType === 'recipe'
                ? array(
                    array(
                        'key'   => 'field_type',
                        'name'  => 'product_type',
                        'label' => 'Product Type',
                        'type'  => 'select',
                    ),
                    array(
                        'key'   => 'field_ingredients',
                        'name'  => 'ingredients',
                        'label' => 'Ingredients',
                        'type'  => 'textarea',
                    ),
                    array(
                        'key'   => 'field_repair_upc',
                        'name'  => 'repair_upc',
                        'label' => 'Repair UPC',
                        'type'  => 'true_false',
                    ),
                    array(
                        'key'   => 'field_show_new_tag',
                        'name'  => 'show_new_tag',
                        'label' => 'Show New Tag',
                        'type'  => 'true_false',
                    ),
                    array(
                        'key'   => 'field_page_id',
                        'name'  => 'page_id',
                        'label' => 'Page ID',
                        'type'  => 'text',
                    ),
                    array(
                        'key'   => 'field_cook_time',
                        'name'  => 'cook_time',
                        'label' => 'Cook Time',
                        'type'  => 'number',
                    ),
                    array(
                        'key'   => 'field_total_time',
                        'name'  => 'total_time',
                        'label' => 'Total Time',
                        'type'  => 'number',
                    ),
                )
                : array()
        );
    }
}
