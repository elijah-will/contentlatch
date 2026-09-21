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

defined('ABSPATH') || exit;

use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\Operators\OperatorRegistry;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\Validators\ValidatorRegistry;

final class RuleDocumentValidator
{
    public const MSG_EMPTY_AND_REQUIRED = 'This rule cannot be saved because a field cannot be required when the rule only applies when that same field is empty.';
    public const MSG_NOT_EMPTY_AND_REQUIRED = 'This rule cannot be saved because a field is already required to have a value by the WHEN condition.';
    public const MSG_EQUALS_AND_REQUIRED = 'This rule cannot be saved because a field that must already have a specific value does not need to be required.';
    public const MSG_MIN_GT_MAX = 'Minimum length cannot be greater than maximum length.';
    public const MSG_MISSING_THEN = 'Each requirement needs a field and a validator.';
    public const MSG_MISSING_WHEN_OR_THEN = 'Add a WHEN condition or THEN requirement.';
    public const MSG_ALLOWED_VALUES = 'Enter at least one allowed value.';
    public const MSG_EMPTY_AND_NOT_EMPTY = 'This rule cannot be saved because a field cannot be both empty and not empty.';

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
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new InvalidRuleException(I18n::translate('Rule document must be valid JSON.'));
        }

        return $this->validateArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validateArray(array $data): Rule
    {
        if ((int) ($data['schema_version'] ?? 0) !== Rule::SCHEMA_VERSION) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new InvalidRuleException(I18n::translate('Unsupported rule schema version.'));
        }

        $logic = (string) ($data['condition_logic'] ?? Rule::CONDITION_LOGIC_AND);
        if ($logic !== Rule::CONDITION_LOGIC_AND) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new InvalidRuleException(I18n::translate('Only AND condition logic is supported.'));
        }

        $rule = Rule::fromArray($data);
        $this->validateRule($rule);

        return $rule;
    }

    public function validateRule(Rule $rule): void
    {
        if ($rule->schemaVersion !== Rule::SCHEMA_VERSION) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new InvalidRuleException(I18n::translate('Unsupported rule schema version.'));
        }

        if ($rule->conditionLogic !== Rule::CONDITION_LOGIC_AND) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new InvalidRuleException(I18n::translate('Only AND condition logic is supported.'));
        }

        foreach ($rule->conditions as $condition) {
            if ($condition->field->key === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                throw new InvalidRuleException(I18n::translate('Field key is required.'));
            }

            if (!$this->operators->has($condition->operator)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                throw new InvalidRuleException(
                    I18n::sprintf(
                        /* translators: %s: operator id */
                        I18n::translate('Unknown condition operator "%s".'),
                        $condition->operator
                    )
                );
            }
        }

        if ($rule->conditions === array() && $rule->validations === array()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new InvalidRuleException(I18n::translate(self::MSG_MISSING_WHEN_OR_THEN));
        }

        foreach ($rule->validations as $validation) {
            if ($validation->field->key === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                throw new InvalidRuleException(I18n::translate('Field key is required.'));
            }

            if (!$this->validators->has($validation->type)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                throw new InvalidRuleException(
                    I18n::sprintf(
                        /* translators: %s: validation type id */
                        I18n::translate('Unknown validation type "%s".'),
                        $validation->type
                    )
                );
            }

            if ($validation->type === 'allowed_values') {
                $values = $validation->params['values'] ?? array();
                if (!is_array($values) || $values === array()) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                    throw new InvalidRuleException(I18n::translate(self::MSG_ALLOWED_VALUES));
                }
            }
        }

        $this->validateLogic($rule);
    }

    private function validateLogic(Rule $rule): void
    {
        $operatorsByField = array();
        foreach ($rule->conditions as $condition) {
            $operatorsByField[$condition->field->resolutionId()][] = $condition->operator;
        }

        foreach ($operatorsByField as $operators) {
            if (in_array('is_empty', $operators, true) && in_array('is_not_empty', $operators, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                throw new InvalidRuleException(I18n::translate(self::MSG_EMPTY_AND_NOT_EMPTY));
            }
        }

        $minByField = array();
        $maxByField = array();

        foreach ($rule->validations as $validation) {
            $key = $validation->field->resolutionId();
            $fieldOps = $operatorsByField[$key] ?? array();

            if ($validation->type === 'required') {
                if (in_array('is_empty', $fieldOps, true)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                    throw new InvalidRuleException(I18n::translate(self::MSG_EMPTY_AND_REQUIRED));
                }
                if (in_array('is_not_empty', $fieldOps, true)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                    throw new InvalidRuleException(I18n::translate(self::MSG_NOT_EMPTY_AND_REQUIRED));
                }
                if (in_array('equals', $fieldOps, true)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                    throw new InvalidRuleException(I18n::translate(self::MSG_EQUALS_AND_REQUIRED));
                }
            }

            if ($validation->type === 'min_length' && isset($validation->params['min'])) {
                $minByField[$key] = (int) $validation->params['min'];
            }
            if ($validation->type === 'max_length' && isset($validation->params['max'])) {
                $maxByField[$key] = (int) $validation->params['max'];
            }
        }

        foreach ($minByField as $key => $min) {
            if (isset($maxByField[$key]) && $min > $maxByField[$key]) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
                throw new InvalidRuleException(I18n::translate(self::MSG_MIN_GT_MAX));
            }
        }
    }
}
