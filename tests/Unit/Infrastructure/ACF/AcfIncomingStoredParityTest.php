<?php
/**
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

final class AcfIncomingStoredParityTest extends TestCase
{
    public function testOmittedTrueFalseIncomingMatchesStoredZeroForEmptiness(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions'  => array(
                    RuleFactory::condition(
                        array(
                            'operator' => 'is_empty',
                            'operand'  => null,
                            'field'    => RuleFactory::field('field_featured', 'featured', 'Featured'),
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field('field_alt', 'alt', 'Alt'),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $fieldTypes = array(
            'field_featured' => 'true_false',
            'field_alt'      => 'text',
        );
        $evaluator = new ContentEvaluator(
            new InMemoryRuleRepository(array($rule)),
            RuleEngine::v1()
        );
        $normalizer = new AcfValueNormalizer();

        $incoming = new AcfIncomingValueProvider(
            array('field_alt' => 'fallback'),
            $normalizer,
            $fieldTypes
        );
        $stored = new AcfStoredValueProvider(
            3,
            $normalizer,
            $fieldTypes,
            static function (string $key): mixed {
                return array(
                    'field_featured' => 0,
                    'field_alt'      => 'fallback',
                )[$key] ?? null;
            }
        );

        $incomingResult = $evaluator->evaluate(3, 'product', $incoming);
        $storedResult   = $evaluator->evaluate(3, 'product', $stored);

        $this->assertTrue($incomingResult->isPassed());
        $this->assertTrue($storedResult->isPassed());
        $this->assertSame($incomingResult->status, $storedResult->status);
        $this->assertFalse($incoming->has('field_featured'));
        $this->assertTrue($stored->has('field_featured'));
        $this->assertFalse($stored->get('field_featured'));
    }

    public function testIncomingTrueFalseOneMatchesStoredTrueForEquals(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions'  => array(
                    RuleFactory::condition(
                        array(
                            'operator' => 'equals',
                            'operand'  => '1',
                            'field'    => RuleFactory::field('field_signature', 'is_signature', 'Is Signature'),
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field(
                                'field_description',
                                'recipe_description',
                                'Recipe Description'
                            ),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $fieldTypes = array(
            'field_signature'   => 'true_false',
            'field_description' => 'textarea',
        );
        $evaluator  = new ContentEvaluator(
            new InMemoryRuleRepository(array($rule)),
            RuleEngine::v1()
        );
        $normalizer = new AcfValueNormalizer();

        $incomingEmpty = $evaluator->evaluate(
            7,
            'product',
            new AcfIncomingValueProvider(
                array(
                    'field_signature'   => '1',
                    'field_description' => '',
                ),
                $normalizer,
                $fieldTypes
            )
        );
        $storedEmpty = $evaluator->evaluate(
            7,
            'product',
            new AcfStoredValueProvider(
                7,
                $normalizer,
                $fieldTypes,
                static function (string $key): mixed {
                    return array(
                        'field_signature'   => 1,
                        'field_description' => '',
                    )[$key] ?? null;
                }
            )
        );

        $this->assertTrue($incomingEmpty->isFailed());
        $this->assertTrue($storedEmpty->isFailed());
        $this->assertSame($incomingEmpty->status, $storedEmpty->status);
        $this->assertSame('required', $incomingEmpty->results[0]->code);
        $this->assertSame('required', $storedEmpty->results[0]->code);

        $incomingFilled = $evaluator->evaluate(
            7,
            'product',
            new AcfIncomingValueProvider(
                array(
                    'field_signature'   => '1',
                    'field_description' => 'Filled',
                ),
                $normalizer,
                $fieldTypes
            )
        );
        $storedFilled = $evaluator->evaluate(
            7,
            'product',
            new AcfStoredValueProvider(
                7,
                $normalizer,
                $fieldTypes,
                static function (string $key): mixed {
                    return array(
                        'field_signature'   => true,
                        'field_description' => 'Filled',
                    )[$key] ?? null;
                }
            )
        );

        $this->assertTrue($incomingFilled->isPassed());
        $this->assertTrue($storedFilled->isPassed());
    }

    public function testDateAndDateTimeScalarsPassThroughWithoutLocaleParsing(): void
    {
        $normalizer = new AcfValueNormalizer();

        $this->assertSame('20260904', $normalizer->normalize('20260904', 'date_picker'));
        $this->assertSame('2026-09-04', $normalizer->normalize('2026-09-04', 'date_picker'));
        $this->assertSame(
            '2026-09-04 13:00:00',
            $normalizer->normalize('2026-09-04 13:00:00', 'date_time_picker')
        );
        $this->assertNull($normalizer->normalize(array('year' => 2026), 'date_picker'));
    }

    public function testSameDateStringsProduceTheSameEvaluationOnBothProviders(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions' => array(
                    RuleFactory::condition(
                        array(
                            'operator' => 'equals',
                            'operand'  => '2026-09-04',
                            'field'    => RuleFactory::field('field_date', 'event_date', 'Event Date'),
                        )
                    ),
                ),
            )
        );
        $fieldTypes = array(
            'field_date'        => 'date_picker',
            'field_ingredients' => 'textarea',
        );
        $evaluator = new ContentEvaluator(
            new InMemoryRuleRepository(array($rule)),
            RuleEngine::v1()
        );
        $normalizer = new AcfValueNormalizer();

        $incoming = new AcfIncomingValueProvider(
            array(
                'field_date'        => '2026-09-04',
                'field_ingredients' => 'ok',
            ),
            $normalizer,
            $fieldTypes
        );
        $stored = new AcfStoredValueProvider(
            4,
            $normalizer,
            $fieldTypes,
            static function (string $key): mixed {
                return array(
                    'field_date'        => '2026-09-04',
                    'field_ingredients' => 'ok',
                )[$key] ?? null;
            }
        );

        $this->assertTrue($evaluator->evaluate(4, 'product', $incoming)->isPassed());
        $this->assertTrue($evaluator->evaluate(4, 'product', $stored)->isPassed());
    }
}
