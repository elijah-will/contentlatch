<?php
/**
 * WHEN clause: field + operator + operand.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain;

use ContentLatch\Domain\Exception\InvalidRuleException;

final class Condition
{
    public function __construct(
        public readonly string $id,
        public readonly FieldRef $field,
        public readonly string $operator,
        public readonly mixed $operand = null,
    ) {
        if ($this->id === '') {
            throw new InvalidRuleException('Condition id is required.');
        }

        if ($this->operator === '') {
            throw new InvalidRuleException('Condition operator is required.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'id'       => $this->id,
            'field'    => $this->field->toArray(),
            'operator' => $this->operator,
            'operand'  => $this->operand,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $field = $data['field'] ?? array();

        if (!is_array($field)) {
            throw new InvalidRuleException('Condition field must be an object.');
        }

        return new self(
            (string) ($data['id'] ?? ''),
            FieldRef::fromArray($field),
            (string) ($data['operator'] ?? ''),
            $data['operand'] ?? null,
        );
    }
}
