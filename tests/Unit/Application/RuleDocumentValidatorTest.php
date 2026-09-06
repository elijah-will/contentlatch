<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\Exception\UnknownOperatorException;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Tests\Support\RuleFactory;
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
        $document['conditions'][0]['operator'] = 'contains';

        try {
            $this->validator->validateArray($document);
            $this->fail('Expected InvalidRuleException');
        } catch (InvalidRuleException $exception) {
            $this->assertStringContainsString('contains', $exception->getMessage());
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
        $this->expectException(\ContentGuard\Domain\Exception\UnknownValidatorException::class);
        RuleEngine::v1()->evaluate(
            array($engineRule),
            new ArrayValueProvider(array('field_type' => 'sauce', 'field_ingredients' => 'ok'))
        );
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->validator->decode('{not-json');
    }
}
