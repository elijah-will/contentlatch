<?php
/**
 * Evaluates rules against a field value provider.
 *
 * This class has no WordPress, ACF, or I/O dependencies.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\Operators\OperatorRegistry;
use ContentGuard\Domain\Validators\ValidatorRegistry;

final class RuleEngine
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

    /**
     * @param Rule[] $rules
     */
    public function evaluate(
        array $rules,
        FieldValueProviderInterface $provider,
        ?int $postId = null,
    ): ContentEvaluation {
        $results = array();

        foreach ($rules as $rule) {
            foreach ($this->evaluateRule($rule, $provider, $postId) as $result) {
                $results[] = $result;
            }
        }

        return ContentEvaluation::fromResults($postId, $results);
    }

    /**
     * @return EvaluationResult[]
     */
    public function evaluateRule(
        Rule $rule,
        FieldValueProviderInterface $provider,
        ?int $postId = null,
    ): array {
        if (!$this->conditionsMatch($rule, $provider)) {
            return array(
                new EvaluationResult(
                    EvaluationStatus::Skipped,
                    $rule->id,
                    $postId,
                    null,
                    'Rule skipped because its conditions were not met.',
                    $rule->severity,
                    'conditions_not_met',
                ),
            );
        }

        if ($rule->validations === array()) {
            return array(
                new EvaluationResult(
                    EvaluationStatus::Passed,
                    $rule->id,
                    $postId,
                    null,
                    'Rule applied with no validations.',
                    $rule->severity,
                    'no_validations',
                ),
            );
        }

        $results = array();

        foreach ($rule->validations as $validation) {
            $results[] = $this->evaluateValidation($rule, $validation, $provider, $postId);
        }

        return $results;
    }

    private function conditionsMatch(Rule $rule, FieldValueProviderInterface $provider): bool
    {
        foreach ($rule->conditions as $condition) {
            $operator = $this->operators->get($condition->operator);
            $value    = $this->read($provider, $condition->field->key);

            if (!$operator->matches($value, $condition->operand)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateValidation(
        Rule $rule,
        Validation $validation,
        FieldValueProviderInterface $provider,
        ?int $postId,
    ): EvaluationResult {
        $validator = $this->validators->get($validation->type);
        $value     = $this->read($provider, $validation->field->key);
        $outcome   = $validator->validate($value, $validation->params);

        $context = $outcome->context;
        $context['field_name']     = $validation->field->name;
        $context['field_label']    = $validation->field->label;
        $context['validation_id']  = $validation->id;

        if ($outcome->passed) {
            return new EvaluationResult(
                EvaluationStatus::Passed,
                $rule->id,
                $postId,
                $validation->field->key,
                $outcome->message,
                $rule->severity,
                $outcome->code,
                $context,
            );
        }

        $status = $rule->severity === RuleSeverity::Warning
            ? EvaluationStatus::Warning
            : EvaluationStatus::Failed;

        $message = $validation->message !== '' ? $validation->message : $outcome->message;

        return new EvaluationResult(
            $status,
            $rule->id,
            $postId,
            $validation->field->key,
            $message,
            $rule->severity,
            $outcome->code,
            $context,
        );
    }

    private function read(FieldValueProviderInterface $provider, string $fieldId): mixed
    {
        if (!$provider->has($fieldId)) {
            return null;
        }

        return $provider->get($fieldId);
    }
}
