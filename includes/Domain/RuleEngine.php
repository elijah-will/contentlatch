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
use ContentGuard\Domain\Contracts\OperatorInterface;
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
            // Condition-only rules have no THEN to satisfy. A non-matching WHEN
            // means the governance condition did not fire, so the content passes.
            // WHEN+THEN rules still skip: the validations did not apply.
            $status = ($rule->validations === array() && $rule->conditions !== array())
                ? EvaluationStatus::Passed
                : EvaluationStatus::Skipped;

            return array(
                new EvaluationResult(
                    $status,
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
            if ($rule->conditions === array()) {
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

            return array($this->evaluateConditionOnly($rule, $provider, $postId));
        }

        $results = array();

        foreach ($rule->validations as $validation) {
            foreach ($this->evaluateValidation($rule, $validation, $provider, $postId) as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function conditionsMatch(Rule $rule, FieldValueProviderInterface $provider): bool
    {
        foreach ($rule->conditions as $condition) {
            $operator = $this->operators->get($condition->operator);
            if (!$this->conditionMatches($operator, $condition, $provider)) {
                return false;
            }
        }

        return true;
    }

    private function conditionMatches(
        OperatorInterface $operator,
        Condition $condition,
        FieldValueProviderInterface $provider,
    ): bool {
        $instances = $provider->instances($condition->field->resolutionId());
        if ($instances === array()) {
            return $operator->matches(null, $condition->operand);
        }

        foreach ($instances as $instance) {
            if (!$instance instanceof FieldInstance) {
                continue;
            }

            if ($operator->matches($instance->value, $condition->operand)) {
                return true;
            }
        }

        return false;
    }

    private function evaluateConditionOnly(
        Rule $rule,
        FieldValueProviderInterface $provider,
        ?int $postId,
    ): EvaluationResult {
        $condition = $rule->conditions[0];
        $field     = $condition->field;
        $context   = array(
            'field_name'  => $field->name,
            'field_label' => $field->label,
        );

        foreach ($rule->conditions as $candidate) {
            $operator = $this->operators->get($candidate->operator);
            foreach ($provider->instances($candidate->field->resolutionId()) as $instance) {
                if (!$instance instanceof FieldInstance) {
                    continue;
                }

                if ($operator->matches($instance->value, $candidate->operand)) {
                    $field   = $candidate->field;
                    $context = array_merge($instance->context, array(
                        'field_name'  => $field->name,
                        'field_label' => $field->label,
                    ));
                    break 2;
                }
            }
        }

        $status = $rule->severity === RuleSeverity::Warning
            ? EvaluationStatus::Warning
            : EvaluationStatus::Failed;

        return new EvaluationResult(
            $status,
            $rule->id,
            $postId,
            $field->resolutionId(),
            $this->conditionMatchedMessage($rule),
            $rule->severity,
            'condition_matched',
            $context,
        );
    }

    private function conditionMatchedMessage(Rule $rule): string
    {
        return $rule->message !== ''
            ? $rule->message
            : 'This content matches the rule condition.';
    }

    /**
     * @return EvaluationResult[]
     */
    private function evaluateValidation(
        Rule $rule,
        Validation $validation,
        FieldValueProviderInterface $provider,
        ?int $postId,
    ): array {
        if ($validation->isEveryInstance()) {
            return $this->evaluateEveryInstance($rule, $validation, $provider, $postId);
        }

        return array(
            $this->resultForValue(
                $rule,
                $validation,
                $this->read($provider, $validation->field->resolutionId()),
                $postId
            ),
        );
    }

    /**
     * @return EvaluationResult[]
     */
    private function evaluateEveryInstance(
        Rule $rule,
        Validation $validation,
        FieldValueProviderInterface $provider,
        ?int $postId,
    ): array {
        $instances = $provider->instances($validation->field->resolutionId());
        if ($instances === array()) {
            if (!$validation->isEveryRow()) {
                return array();
            }

            $context = array(
                'field_name'    => $validation->field->name,
                'field_label'   => $validation->field->label,
                'validation_id' => $validation->id,
                'repeater_label'=> $this->repeaterLabel($validation->field),
            );

            $status = $rule->severity === RuleSeverity::Warning
                ? EvaluationStatus::Warning
                : EvaluationStatus::Failed;

            return array(
                new EvaluationResult(
                    $status,
                    $rule->id,
                    $postId,
                    $validation->field->resolutionId(),
                    sprintf('Add at least one %s row.', $this->repeaterLabel($validation->field)),
                    $rule->severity,
                    'no_rows',
                    $context,
                ),
            );
        }

        $failed = array();
        $passed = array();

        foreach ($instances as $instance) {
            if (!$instance instanceof FieldInstance) {
                continue;
            }

            $result = $this->resultForValue(
                $rule,
                $validation,
                $instance->value,
                $postId,
                $instance->context
            );

            if ($result->isFailed() || $result->isWarning()) {
                $failed[] = $result;
            } else {
                $passed[] = $result;
            }
        }

        if ($failed !== array()) {
            return $failed;
        }

        return $passed !== array() ? array($passed[0]) : array();
    }

    /**
     * @param array<string, mixed> $extraContext
     */
    private function resultForValue(
        Rule $rule,
        Validation $validation,
        mixed $value,
        ?int $postId,
        array $extraContext = array(),
    ): EvaluationResult {
        $validator = $this->validators->get($validation->type);
        $outcome   = $validator->validate($value, $validation->params);

        $context = array_merge($outcome->context, $extraContext);
        $context['field_name']     = $validation->field->name;
        $context['field_label']    = $validation->field->label;
        $context['validation_id']  = $validation->id;

        if ($outcome->passed) {
            return new EvaluationResult(
                EvaluationStatus::Passed,
                $rule->id,
                $postId,
                $validation->field->resolutionId(),
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
            $validation->field->resolutionId(),
            $message,
            $rule->severity,
            $outcome->code,
            $context,
        );
    }

    private function repeaterLabel(FieldRef $field): string
    {
        $parts = preg_split('/\s*→\s*/u', $field->label) ?: array();
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));

        if (count($parts) >= 2) {
            return $parts[count($parts) - 2];
        }

        if ($field->name !== '') {
            return $field->name;
        }

        return 'Repeater';
    }

    private function read(FieldValueProviderInterface $provider, string $fieldId): mixed
    {
        if (!$provider->has($fieldId)) {
            return null;
        }

        return $provider->get($fieldId);
    }
}
