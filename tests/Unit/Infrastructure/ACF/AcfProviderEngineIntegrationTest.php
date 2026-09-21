<?php
/**
 * Proves stored and incoming ACF providers share the domain engine.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfIncomingValueProvider;
use ContentGuard\Infrastructure\ACF\AcfStoredValueProvider;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AcfProviderEngineIntegrationTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $fieldTypes = array(
        'field_type'        => 'select',
        'field_ingredients' => 'textarea',
        'field_body'        => 'wysiwyg',
    );

    public function testIncomingAndStoredProvidersProduceTheSameEvaluation(): void
    {
        $evaluator = new ContentEvaluator(
            new InMemoryRuleRepository(array(RuleFactory::rule())),
            RuleEngine::v1()
        );
        $normalizer = new AcfValueNormalizer();

        $incoming = new AcfIncomingValueProvider(
            array(
                'field_type'        => array(
                    'value' => 'sauce',
                    'label' => 'Sauce',
                ),
                'field_ingredients' => 'tomatoes',
            ),
            $normalizer,
            $this->fieldTypes
        );

        $stored = new AcfStoredValueProvider(
            21,
            $normalizer,
            $this->fieldTypes,
            static function (string $key): mixed {
                $values = array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => 'tomatoes',
                );

                return $values[$key] ?? null;
            }
        );

        $incomingResult = $evaluator->evaluate(21, 'product', $incoming);
        $storedResult   = $evaluator->evaluate(21, 'product', $stored);

        $this->assertTrue($incomingResult->isPassed());
        $this->assertTrue($storedResult->isPassed());
        $this->assertSame($incomingResult->status, $storedResult->status);
        $this->assertSame($incomingResult->results[0]->code, $storedResult->results[0]->code);
        $this->assertSame($incomingResult->results[0]->fieldId, $storedResult->results[0]->fieldId);
    }

    public function testWysiwygLengthUsesNormalizedTextNotHtml(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'  => RuleFactory::field('field_body', 'body', 'Body'),
                            'type'   => 'min_length',
                            'params' => array('min' => 10),
                        )
                    ),
                ),
            )
        );
        $evaluator = new ContentEvaluator(
            new InMemoryRuleRepository(array($rule)),
            RuleEngine::v1()
        );

        $tooShort = new AcfIncomingValueProvider(
            array('field_body' => '<p>Hi</p>'),
            new AcfValueNormalizer(),
            $this->fieldTypes
        );
        $longEnough = new AcfIncomingValueProvider(
            array('field_body' => '<p>' . str_repeat('a', 10) . '</p>'),
            new AcfValueNormalizer(),
            $this->fieldTypes
        );

        $this->assertTrue($evaluator->evaluate(1, 'product', $tooShort)->isFailed());
        $this->assertTrue($evaluator->evaluate(1, 'product', $longEnough)->isPassed());
    }
}
