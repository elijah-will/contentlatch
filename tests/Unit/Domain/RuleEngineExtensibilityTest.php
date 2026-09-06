<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain;

use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\Contracts\OperatorInterface;
use ContentGuard\Domain\Contracts\ValidatorInterface;
use ContentGuard\Domain\Operators\OperatorRegistry;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\ValidatorOutcome;
use ContentGuard\Domain\Validators\ValidatorRegistry;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleEngineExtensibilityTest extends TestCase
{
    public function testRegisteredOperatorIsEvaluatedWithoutChangingTheEngine(): void
    {
        $operators = OperatorRegistry::v1();
        $operators->register(
            'contains',
            new class implements OperatorInterface {
                public function matches(mixed $value, mixed $operand): bool
                {
                    return is_string($value) && is_string($operand) && str_contains($value, $operand);
                }
            }
        );

        $engine = new RuleEngine($operators, ValidatorRegistry::v1());
        $rule   = RuleFactory::rule(
            array(
                'conditions' => array(
                    RuleFactory::condition(
                        array(
                            'operator' => 'contains',
                            'operand'  => 'sau',
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
