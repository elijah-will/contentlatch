<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application;

use ContentLatch\Application\RuleDocumentValidator;
use ContentLatch\Domain\Exception\InvalidRuleException;
use ContentLatch\Domain\Exception\UnknownOperatorException;
use ContentLatch\Domain\ArrayValueProvider;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleDocumentValidatorTest extends TestCase
{
    private RuleDocumentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = RuleDocumentValidator::v1();
    }

    public function testValidDocumentDecodes(): void
    {
        $rule = $this->validator->validateArray(RuleFactory::document());
        $this->assertSame('Example rule', $rule->name);
    }

    public function testInvalidSchemaVersionIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->validator->validateArray(RuleFactory::document(array('schema_version' => 2)));
    }

    public function testUnknownOperatorIsRejectedAtThePersistenceBoundary(): void
    {
        $document = RuleFactory::document();
        $document['conditions'][0]['operator'] = 'starts_with';

        try {
            $this->validator->validateArray($document);
            $this->fail('Expected InvalidRuleException');
        } catch (InvalidRuleException $exception) {
            $this->assertStringContainsString('starts_with', $exception->getMessage());
        }

        $engineRule = Rule::fromArray($document);
        $this->expectException(UnknownOperatorException::class);
        RuleEngine::v1()->evaluate(
            array($engineRule),
            new ArrayValueProvider(array('field_type' => 'sauce'))
        );
    }

    public function testUnknownValidatorIsRejectedAtThePersistenceBoundary(): void
    {
        $document = RuleFactory::document();
        $document['validations'][0]['type'] = 'regex';

        try {
            $this->validator->validateArray($document);
            $this->fail('Expected InvalidRuleException');
        } catch (InvalidRuleException $exception) {
            $this->assertStringContainsString('regex', $exception->getMessage());
        }

        $engineRule = Rule::fromArray($document);
        $this->expectException(\ContentLatch\Domain\Exception\UnknownValidatorException::class);
        RuleEngine::v1()->evaluate(
            array($engineRule),
            new ArrayValueProvider(array('field_type' => 'sauce', 'field_ingredients' => 'ok'))
        );
    }

    public function testInvalidNestedPathIsRejected(): void
    {
        $document = RuleFactory::document();
        $document['validations'][0]['field'] = array(
            'key'       => 'field_ingredients',
            'name'      => 'ingredients',
            'label'     => 'Ingredients',
            'path'      => array('field_group.field_child', 'field_ingredients'),
            'container' => 'group',
        );

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Invalid field path.');
        $this->validator->validateArray($document);
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->validator->decode('{not-json');
    }

    public function testEmptyPlusSameFieldRequiredIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_EMPTY_AND_REQUIRED);
        $this->validator->validateArray($this->sameFieldRule('is_empty', 'required'));
    }

    public function testNotEmptyPlusSameFieldRequiredIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_NOT_EMPTY_AND_REQUIRED);
        $this->validator->validateArray($this->sameFieldRule('is_not_empty', 'required'));
    }

    public function testEqualsPlusSameFieldRequiredIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_EQUALS_AND_REQUIRED);
        $this->validator->validateArray($this->sameFieldRule('equals', 'required', '1'));
    }

    public function testMinGreaterThanMaxIsRejected(): void
    {
        $document = RuleFactory::document();
        $document['conditions'] = array();
        $document['validations'] = array(
            RuleFactory::validation(array('type' => 'min_length', 'params' => array('min' => 50)))->toArray(),
            RuleFactory::validation(array(
                'id'     => 'v2',
                'type'   => 'max_length',
                'params' => array('max' => 10),
            ))->toArray(),
        );

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_MIN_GT_MAX);
        $this->validator->validateArray($document);
    }

    public function testRequiredPlusMinLengthOnSameFieldIsValid(): void
    {
        $document = RuleFactory::document();
        $document['conditions'] = array();
        $document['validations'] = array(
            RuleFactory::validation(array('type' => 'required'))->toArray(),
            RuleFactory::validation(array(
                'id'     => 'v2',
                'type'   => 'min_length',
                'params' => array('min' => 50),
            ))->toArray(),
        );

        $rule = $this->validator->validateArray($document);
        $this->assertCount(2, $rule->validations);
        $this->assertSame('required', $rule->validations[0]->type);
        $this->assertSame('min_length', $rule->validations[1]->type);
    }

    public function testConditionalRequiredOnADifferentFieldIsValid(): void
    {
        $document = RuleFactory::document();
        $document['conditions'] = array(
            RuleFactory::condition(array(
                'field'    => RuleFactory::field('field_show_new_tag', 'show_new_tag', 'Show New Tag'),
                'operator' => 'equals',
                'operand'  => '1',
            ))->toArray(),
        );
        $document['validations'] = array(
            RuleFactory::validation(array(
                'field' => RuleFactory::field('field_page_id', 'page_id', 'Page ID'),
                'type'  => 'required',
            ))->toArray(),
        );

        $rule = $this->validator->validateArray($document);
        $this->assertSame('1', $rule->conditions[0]->operand);
        $this->assertSame('field_page_id', $rule->validations[0]->field->key);
    }

    public function testExistingSchemaOneDocumentsRemainReadable(): void
    {
        $rule = $this->validator->validateArray(RuleFactory::document());
        $this->assertSame(1, $rule->schemaVersion);
        $this->assertSame('equals', $rule->conditions[0]->operator);
        $this->assertArrayNotHasKey('message', RuleFactory::document());
    }

    public function testConditionOnlyDocumentIsValid(): void
    {
        $document = RuleFactory::document(array(
            'validations' => array(),
            'message'     => 'Please avoid the term "healthy" in recipe content.',
        ));
        $document['conditions'][0]['operator'] = 'contains';
        $document['conditions'][0]['operand'] = 'healthy';
        $document['conditions'][0]['field'] = RuleFactory::field(
            'field_ingredients',
            'ingredients',
            'Ingredients'
        )->toArray();

        $rule = $this->validator->validateArray($document);
        $this->assertSame(array(), $rule->validations);
        $this->assertSame('contains', $rule->conditions[0]->operator);
        $this->assertSame('Please avoid the term "healthy" in recipe content.', $rule->message);
        $this->assertSame(1, $rule->schemaVersion);
    }

    public function testEmptyConditionsAndValidationsAreRejected(): void
    {
        $document = RuleFactory::document();
        $document['conditions'] = array();
        $document['validations'] = array();

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_MISSING_WHEN_OR_THEN);
        $this->validator->validateArray($document);
    }

    public function testNumericOperatorsAreAcceptedWithoutSchemaChange(): void
    {
        $document = RuleFactory::document();
        $document['conditions'][0]['operator'] = 'greater_than';
        $document['conditions'][0]['operand'] = '30';

        $rule = $this->validator->validateArray($document);
        $this->assertSame(1, $rule->schemaVersion);
        $this->assertSame('greater_than', $rule->conditions[0]->operator);
        $this->assertSame('30', $rule->conditions[0]->operand);
    }

    public function testEmptyAllowedValuesAreRejected(): void
    {
        $document = RuleFactory::document();
        $document['validations'][0]['type'] = 'allowed_values';
        $document['validations'][0]['params'] = array('values' => array());

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage(RuleDocumentValidator::MSG_ALLOWED_VALUES);
        $this->validator->validateArray($document);
    }

    /**
     * @return array<string, mixed>
     */
    private function sameFieldRule(string $operator, string $validator, ?string $operand = null): array
    {
        $field = RuleFactory::field('field_page_id', 'page_id', 'Page ID');
        $document = RuleFactory::document();
        $document['conditions'] = array(
            RuleFactory::condition(array(
                'field'    => $field,
                'operator' => $operator,
                'operand'  => $operand,
            ))->toArray(),
        );
        $document['validations'] = array(
            RuleFactory::validation(array(
                'field' => $field,
                'type'  => $validator,
            ))->toArray(),
        );

        return $document;
    }
}
