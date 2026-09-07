<?php
/**
 * Builds versioned Rule documents from admin form input.
 *
 * Field keys and post types are allowlisted by injected callbacks so
 * this class stays free of WordPress/ACF I/O.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\Operators\OperatorRegistry;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\Validators\ValidatorRegistry;
use ContentGuard\Domain\Value;

final class RuleDocumentFactory
{
    /**
     * @param callable(): array<string, string> $postTypes slug => label
     * @param callable(string $postType): array<int, array<string, mixed>> $fieldsForPostType
     */
    public function __construct(
        private RuleDocumentValidator $validator,
        private OperatorRegistry $operators,
        private ValidatorRegistry $validators,
        private mixed $postTypes,
        private mixed $fieldsForPostType,
    ) {
    }

    public static function v1(mixed $postTypes, mixed $fieldsForPostType): self
    {
        return new self(
            RuleDocumentValidator::v1(),
            OperatorRegistry::v1(),
            ValidatorRegistry::v1(),
            $postTypes,
            $fieldsForPostType
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function fromAdminInput(array $input): Rule
    {
        $name     = trim((string) ($input['name'] ?? ''));
        $postType = self::sanitizeKey((string) ($input['target_post_type'] ?? $input['post_type'] ?? ''));
        $status   = (string) ($input['status'] ?? 'active');
        $severity = (string) ($input['severity'] ?? 'fail');
        $default  = trim((string) ($input['message'] ?? ''));
        $id       = $input['rule_id'] ?? $input['id'] ?? '';

        if ($name === '') {
            throw new InvalidRuleException('Rule name is required.');
        }

        $allowedTypes = $this->allowedPostTypes();
        if ($postType === '' || !isset($allowedTypes[$postType])) {
            throw new InvalidRuleException('Unsupported post type.');
        }

        if (!in_array($status, array('active', 'inactive'), true)) {
            throw new InvalidRuleException('Invalid rule status.');
        }

        if (!in_array($severity, array('fail', 'warning'), true)) {
            throw new InvalidRuleException('Invalid rule severity.');
        }

        $fields = $this->fieldsByKey($postType);
        $document = array(
            'schema_version'  => Rule::SCHEMA_VERSION,
            'id'              => $this->normalizeId($id),
            'name'            => $name,
            'post_type'       => $postType,
            'status'          => $status,
            'severity'        => $severity,
            'condition_logic' => Rule::CONDITION_LOGIC_AND,
            'conditions'      => $this->mapConditions($input['conditions'] ?? array(), $fields),
            'validations'     => $this->mapValidations($input['validations'] ?? array(), $fields, $default),
        );

        return $this->validator->validateArray($document);
    }

    /**
     * @return array<string, string>
     */
    public function allowedPostTypes(): array
    {
        $loader = $this->postTypes;
        if (!is_callable($loader)) {
            return array();
        }

        $types = $loader();

        return is_array($types) ? $types : array();
    }

    /**
     * Post types that currently have at least one catalog-supported field.
     *
     * @return array<string, string>
     */
    public function selectablePostTypes(): array
    {
        $selectable = array();

        foreach ($this->allowedPostTypes() as $slug => $label) {
            $slug = (string) $slug;
            if ($this->fieldsByKey($slug) !== array()) {
                $selectable[$slug] = (string) $label;
            }
        }

        return $selectable;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fieldsForPostType(string $postType): array
    {
        if (!isset($this->allowedPostTypes()[$postType])) {
            throw new InvalidRuleException('Unsupported post type.');
        }

        return array_values($this->fieldsByKey($postType));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fieldsByKey(string $postType): array
    {
        $loader = $this->fieldsForPostType;
        if (!is_callable($loader)) {
            return array();
        }

        $loaded = $loader($postType);
        if (!is_array($loaded)) {
            return array();
        }

        $fields = array();
        foreach ($loaded as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            if ($key === '' || !str_starts_with($key, 'field_')) {
                continue;
            }

            $fields[$key] = $field;
        }

        return $fields;
    }

    /**
     * @param mixed $rows
     * @param array<string, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function mapConditions(mixed $rows, array $fields): array
    {
        if (!is_array($rows)) {
            throw new InvalidRuleException('Rule conditions must be an array.');
        }

        $conditions = array();
        $index      = 1;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidRuleException('Rule conditions entries must be objects.');
            }

            $fieldKey = (string) ($row['field_key'] ?? '');
            if ($fieldKey === '') {
                continue;
            }

            $operator = (string) ($row['operator'] ?? '');
            if (!$this->operators->has($operator)) {
                throw new InvalidRuleException(sprintf('Unknown condition operator "%s".', $operator));
            }

            $field     = $this->fieldRef($fieldKey, $fields);
            if ($field->isRepeaterChild()) {
                throw new InvalidRuleException('Repeater fields cannot be used in WHEN conditions.');
            }
            $fieldType = (string) ($fields[$fieldKey]['type'] ?? '');

            if (ConditionOperators::requiresOperand($operator)) {
                $operand = $row['operand'] ?? '';
                if (!is_scalar($operand) && !is_bool($operand)) {
                    throw new InvalidRuleException('A condition value is required.');
                }
                $operand = self::normalizeConditionOperand($operand, $fieldType);
                if ($operand === '') {
                    throw new InvalidRuleException('A condition value is required.');
                }
                if (ConditionOperators::isNumericComparison($operator)) {
                    if (!ConditionOperators::isNumericField($fieldType)) {
                        throw new InvalidRuleException('Numeric comparisons can only be used with number fields.');
                    }
                    if (Value::tryNumber($operand) === null) {
                        throw new InvalidRuleException('A numeric condition value is required.');
                    }
                }
            } else {
                $operand = null;
            }

            $id = trim((string) ($row['id'] ?? ''));
            $conditions[] = array(
                'id'       => $id !== '' ? $id : 'c' . $index,
                'field'    => $field->toArray(),
                'operator' => $operator,
                'operand'  => $operand,
            );
            ++$index;
        }

        return $conditions;
    }

    /**
     * @param mixed $rows
     * @param array<string, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function mapValidations(mixed $rows, array $fields, string $defaultMessage): array
    {
        if (!is_array($rows)) {
            throw new InvalidRuleException('Rule validations must be an array.');
        }

        $validations = array();
        $index       = 1;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidRuleException('Rule validations entries must be objects.');
            }

            $fieldKey = (string) ($row['field_key'] ?? '');
            $type     = (string) ($row['type'] ?? '');
            if ($fieldKey === '' && $type === '') {
                continue;
            }

            if ($fieldKey === '' || $type === '') {
                throw new InvalidRuleException(RuleDocumentValidator::MSG_MISSING_THEN);
            }

            if (!$this->validators->has($type)) {
                throw new InvalidRuleException(sprintf('Unknown validation type "%s".', $type));
            }

            $message = trim((string) ($row['message'] ?? ''));
            if ($message === '') {
                $message = $defaultMessage;
            }

            $id    = trim((string) ($row['id'] ?? ''));
            $field = $this->fieldRef($fieldKey, $fields);
            $item  = array(
                'id'      => $id !== '' ? $id : 'v' . $index,
                'field'   => $field->toArray(),
                'type'    => $type,
                'params'  => $this->validationParams($type, $row),
                'message' => $message,
            );
            if ($field->isRepeaterChild()) {
                $item['quantifier'] = \ContentGuard\Domain\Validation::QUANTIFIER_EVERY;
            }
            $validations[] = $item;
            ++$index;
        }

        if ($validations === array()) {
            throw new InvalidRuleException('At least one validation is required.');
        }

        return $validations;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function validationParams(string $type, array $row): array
    {
        if ($type === 'min_length') {
            $min = $row['min'] ?? ($row['params']['min'] ?? null);
            if (!is_numeric($min) || (int) $min < 0) {
                throw new InvalidRuleException('Minimum length is not configured.');
            }

            return array('min' => (int) $min);
        }

        if ($type === 'max_length') {
            $max = $row['max'] ?? ($row['params']['max'] ?? null);
            if (!is_numeric($max) || (int) $max < 0) {
                throw new InvalidRuleException('Maximum length is not configured.');
            }

            return array('max' => (int) $max);
        }

        if ($type === 'allowed_values') {
            $raw = $row['values'] ?? ($row['params']['values'] ?? null);
            $values = $this->allowedValues($raw);
            if ($values === array()) {
                throw new InvalidRuleException(RuleDocumentValidator::MSG_ALLOWED_VALUES);
            }

            return array('values' => $values);
        }

        return array();
    }

    /**
     * @return array<int, string>
     */
    private function allowedValues(mixed $raw): array
    {
        if (is_string($raw)) {
            $parts = preg_split('/\s*,\s*/', trim($raw)) ?: array();
            $raw   = $parts;
        }

        if (!is_array($raw)) {
            return array();
        }

        $values = array();
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);
            if ($string !== '') {
                $values[] = $string;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     */
    private function fieldRef(string $key, array $fields): FieldRef
    {
        if (!isset($fields[$key])) {
            throw new InvalidRuleException('Unsupported field.');
        }

        $field = $fields[$key];

        $label      = (string) ($field['label'] ?? '');
        $breadcrumb = trim((string) ($field['breadcrumb'] ?? ''));
        if ($breadcrumb !== '') {
            $label = $breadcrumb;
        }

        return new FieldRef(
            $key,
            (string) ($field['name'] ?? ''),
            $label,
            $this->fieldPath($field),
            (string) ($field['container'] ?? '')
        );
    }

    /**
     * @param array<string, mixed> $field
     * @return list<string>
     */
    private function fieldPath(array $field): array
    {
        $raw = $field['path'] ?? array();
        if (!is_array($raw) || $raw === array()) {
            return array();
        }

        $path = array();
        foreach ($raw as $segment) {
            if (!is_string($segment)) {
                throw new InvalidRuleException('Invalid field path.');
            }

            $path[] = $segment;
        }

        return $path;
    }

    private static function normalizeConditionOperand(mixed $operand, string $fieldType): string
    {
        if (is_bool($operand)) {
            $value = $operand ? '1' : '0';
        } else {
            $value = trim((string) $operand);
        }

        if ($fieldType !== 'true_false') {
            return $value;
        }

        $lower = strtolower($value);
        if (in_array($lower, array('1', 'yes', 'true', 'on'), true)) {
            return '1';
        }

        if (in_array($lower, array('0', 'no', 'false', 'off'), true)) {
            return '0';
        }

        return $value;
    }

    private static function sanitizeKey(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_\-]/', '', $value);

        return is_string($value) ? $value : '';
    }

    private function normalizeId(mixed $id): int|string
    {
        if (is_int($id)) {
            return $id;
        }

        if (is_numeric($id) && (int) $id > 0) {
            return (int) $id;
        }

        return '';
    }
}
