<?php
/**
 * Rule builder form state, including invalid submissions.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;

final class RuleEditorState
{
    /**
     * @param list<array{id: string, field_key: string, operator: string, operand: string}> $conditions
     * @param list<array{id: string, field_key: string, type: string, min: string, max: string, values: string, message: string}> $validations
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $name,
        public readonly string $postType,
        public readonly string $severity,
        public readonly string $status,
        public readonly string $message,
        public readonly array $conditions,
        public readonly array $validations,
    ) {
    }

    public function isNew(): bool
    {
        return $this->id === '' || $this->id === 0 || $this->id === '0';
    }

    public static function fromRule(?Rule $rule): self
    {
        if ($rule === null) {
            return new self('', '', '', RuleSeverity::Fail->value, RuleStatus::Active->value, '', array(), array());
        }

        $conditions = array();
        foreach ($rule->conditions as $condition) {
            $conditions[] = array(
                'id'        => $condition->id,
                'field_key' => $condition->field->resolutionId(),
                'operator'  => $condition->operator,
                'operand'   => is_scalar($condition->operand) ? (string) $condition->operand : '',
            );
        }

        $validations = array();
        $messages    = array();
        foreach ($rule->validations as $validation) {
            $values = $validation->params['values'] ?? array();
            $validations[] = array(
                'id'        => $validation->id,
                'field_key' => $validation->field->resolutionId(),
                'type'      => $validation->type,
                'min'       => self::paramString($validation->params['min'] ?? ''),
                'max'       => self::paramString($validation->params['max'] ?? ''),
                'values'    => is_array($values) ? implode(', ', array_map('strval', $values)) : '',
                'message'   => $validation->message,
            );
            $messages[] = $validation->message;
        }

        $unique = array_values(array_unique($messages));

        return new self(
            $rule->id,
            $rule->name,
            $rule->postType,
            $rule->severity->value,
            $rule->status->value,
            count($unique) === 1 ? $unique[0] : '',
            $conditions,
            $validations
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromSubmitted(array $input): self
    {
        $snapshot = self::snapshot($input);

        return new self(
            $snapshot['id'],
            $snapshot['name'],
            $snapshot['post_type'],
            $snapshot['severity'],
            $snapshot['status'],
            $snapshot['message'],
            $snapshot['conditions'],
            $snapshot['validations']
        );
    }

    /**
     * @param array<string, mixed>|null $draft
     */
    public static function hydrate(?array $draft, ?Rule $rule): self
    {
        return $draft !== null ? self::fromSubmitted($draft) : self::fromRule($rule);
    }

    /**
     * Safe subset of builder POST used to rehydrate the editor after a failed save.
     *
     * @param array<string, mixed> $input
     * @return array{
     *     id: int|string,
     *     name: string,
     *     post_type: string,
     *     severity: string,
     *     status: string,
     *     message: string,
     *     conditions: list<array{id: string, field_key: string, operator: string, operand: string}>,
     *     validations: list<array{id: string, field_key: string, type: string, min: string, max: string, values: string, message: string}>
     * }
     */
    public static function snapshot(array $input): array
    {
        $id = $input['rule_id'] ?? $input['id'] ?? '';
        if (is_numeric($id) && (int) $id > 0) {
            $id = (int) $id;
        } else {
            $id = is_scalar($id) ? (string) $id : '';
        }

        return array(
            'id'          => $id,
            'name'        => self::scalar($input['name'] ?? ''),
            'post_type'   => self::scalar($input['target_post_type'] ?? $input['post_type'] ?? ''),
            'severity'    => self::scalar($input['severity'] ?? RuleSeverity::Fail->value),
            'status'      => self::scalar($input['status'] ?? RuleStatus::Active->value),
            'message'     => self::scalar($input['message'] ?? ''),
            'conditions'  => self::conditionRows($input['conditions'] ?? array()),
            'validations' => self::validationRows($input['validations'] ?? array()),
        );
    }

    /**
     * @param mixed $rows
     * @return list<array{id: string, field_key: string, operator: string, operand: string}>
     */
    private static function conditionRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return array();
        }

        $mapped = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mapped[] = array(
                'id'        => self::scalar($row['id'] ?? ''),
                'field_key' => self::scalar($row['field_key'] ?? ''),
                'operator'  => self::scalar($row['operator'] ?? ''),
                'operand'   => self::scalar($row['operand'] ?? ''),
            );
        }

        return $mapped;
    }

    /**
     * @param mixed $rows
     * @return list<array{id: string, field_key: string, type: string, min: string, max: string, values: string, message: string}>
     */
    private static function validationRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return array();
        }

        $mapped = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mapped[] = array(
                'id'        => self::scalar($row['id'] ?? ''),
                'field_key' => self::scalar($row['field_key'] ?? ''),
                'type'      => self::scalar($row['type'] ?? ''),
                'min'       => self::scalar($row['min'] ?? ($row['params']['min'] ?? '')),
                'max'       => self::scalar($row['max'] ?? ($row['params']['max'] ?? '')),
                'values'    => self::valuesText($row['values'] ?? ($row['params']['values'] ?? '')),
                'message'   => self::scalar($row['message'] ?? ''),
            );
        }

        return $mapped;
    }

    private static function valuesText(mixed $raw): string
    {
        if (is_array($raw)) {
            $parts = array();
            foreach ($raw as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = (string) $value;
                }
            }

            return implode(', ', $parts);
        }

        return self::scalar($raw);
    }

    private static function paramString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
