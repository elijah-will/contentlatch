<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Domain;

use ContentLatch\Domain\ArrayValueProvider;
use ContentLatch\Domain\Contracts\OperatorInterface;
use ContentLatch\Domain\Contracts\ValidatorInterface;
use ContentLatch\Domain\Operators\OperatorRegistry;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Domain\ValidatorOutcome;
use ContentLatch\Domain\Validators\ValidatorRegistry;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleEngineExtensibilityTest extends TestCase
{
    public function testRegisteredOperatorIsEvaluatedWithoutChangingTheEngine(): void
    {
        $operators = OperatorRegistry::v1();
        $operators->register(
            'ends_with',
            new class implements OperatorInterface {
                public function matches(mixed $value, mixed $operand): bool
                {
                    return is_string($value) && is_string($operand) && str_ends_with($value, $operand);
                }
            }
        );

        $engine = new RuleEngine($operators, ValidatorRegistry::v1());
        $rule   = RuleFactory::rule(
            array(
                'conditions' => array(
                    RuleFactory::condition(
                        array(
                            'operator' => 'ends_with',
                            'operand'  => 'uce',
                            'field'    => RuleFactory::field('field_type', 'product_type', 'Product Type'),
                        )
                    ),
                ),
            )
        );

        $pass = $engine->evaluate(
            array($rule),
            new ArrayValueProvider(
                array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => 'ok',
                )
            )
        );
        $this->assertTrue($pass->isPassed());

        $skip = $engine->evaluate(
            array($rule),
            new ArrayValueProvider(
                array(
                    'field_type'        => 'dip',
                    'field_ingredients' => 'ok',
                )
            )
        );
        $this->assertTrue($skip->results[0]->isSkipped());
    }

    public function testRegisteredValidatorIsEvaluatedWithoutChangingTheEngine(): void
    {
        $validators = ValidatorRegistry::v1();
        $validators->register(
            'starts_with',
            new class implements ValidatorInterface {
                public function validate(mixed $value, array $params): ValidatorOutcome
                {
                    $prefix = (string) ($params['prefix'] ?? '');
                    if (is_string($value) && str_starts_with($value, $prefix)) {
                        return ValidatorOutcome::pass('starts_with');
                    }

                    return ValidatorOutcome::fail('starts_with', 'Value must start with the required prefix.');
                }
            }
        );

        $engine = new RuleEngine(OperatorRegistry::v1(), $validators);
        $rule   = RuleFactory::rule(
            array(
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'  => RuleFactory::field('field_code', 'code', 'Code'),
                            'type'   => 'starts_with',
                            'params' => array('prefix' => 'SKU-'),
                        )
                    ),
                ),
            )
        );

        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array('field_code' => 'SKU-1'))
            )->isPassed()
        );
        $this->assertTrue(
            $engine->evaluate(
                array($rule),
                new ArrayValueProvider(array('field_code' => 'ITEM-1'))
            )->isFailed()
        );
    }
}
