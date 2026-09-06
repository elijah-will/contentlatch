<?php
/**
 * Validates rule documents at the persistence boundary.
 *
 * Unknown operators/validators are rejected here so RuleEngine is never
 * asked to evaluate a corrupt stored document.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\Operators\OperatorRegistry;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\Validators\ValidatorRegistry;

final class RuleDocumentValidator
{
    public function __construct(
        private OperatorRegistry $operators,
        private ValidatorRegistry $validators,
    ) {
    }

    public static function v1(): self
    {
        return new self(OperatorRegistry::v1(), ValidatorRegistry::v1());
    }

    public function decode(string $json): Rule
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new InvalidRuleException('Rule document must be valid JSON.');
        }

        return $this->validateArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validateArray(array $data): Rule
    {
        if ((int) ($data['schema_version'] ?? 0) !== Rule::SCHEMA_VERSION) {
            throw new InvalidRuleException('Unsupported rule schema version.');
        }

        $logic = (string) ($data['condition_logic'] ?? Rule::CONDITION_LOGIC_AND);
        if ($logic !== Rule::CONDITION_LOGIC_AND) {
            throw new InvalidRuleException('Only AND condition logic is supported.');
        }

        $rule = Rule::fromArray($data);
        $this->validateRule($rule);

        return $rule;
    }

    public function validateRule(Rule $rule): void
    {
        if ($rule->schemaVersion !== Rule::SCHEMA_VERSION) {
            throw new InvalidRuleException('Unsupported rule schema version.');
        }

        if ($rule->conditionLogic !== Rule::CONDITION_LOGIC_AND) {
            throw new InvalidRuleException('Only AND condition logic is supported.');
        }

        foreach ($rule->conditions as $condition) {
            if ($condition->field->key === '') {
                throw new InvalidRuleException('Field key is required.');
            }

            if (!$this->operators->has($condition->operator)) {
                throw new InvalidRuleException(
                    sprintf('Unknown condition operator "%s".', $condition->operator)
                );
            }
        }

        foreach ($rule->validations as $validation) {
            if ($validation->field->key === '') {
                throw new InvalidRuleException('Field key is required.');
            }

            if (!$this->validators->has($validation->type)) {
                throw new InvalidRuleException(
                    sprintf('Unknown validation type "%s".', $validation->type)
                );
            }
        }
    }
}
