<?php
/**
 * THEN clause: field + validator + parameters.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

use ContentGuard\Domain\Exception\InvalidRuleException;

final class Validation
{
    public const QUANTIFIER_EVERY = 'every';

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly string $id,
        public readonly FieldRef $field,
        public readonly string $type,
        public readonly array $params = array(),
        public readonly string $message = '',
        public readonly string $quantifier = '',
    ) {
        if ($this->id === '') {
            throw new InvalidRuleException('Validation id is required.');
        }

        if ($this->type === '') {
            throw new InvalidRuleException('Validation type is required.');
        }

        if ($this->quantifier !== '' && $this->quantifier !== self::QUANTIFIER_EVERY) {
            throw new InvalidRuleException('Unsupported field quantifier.');
        }
    }

    public function isEveryRow(): bool
    {
        return $this->field->isRepeaterChild()
            && ($this->quantifier === '' || $this->quantifier === self::QUANTIFIER_EVERY);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = array(
            'id'      => $this->id,
            'field'   => $this->field->toArray(),
            'type'    => $this->type,
            'params'  => $this->params,
            'message' => $this->message,
        );

        if ($this->quantifier !== '') {
            $data['quantifier'] = $this->quantifier;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $field = $data['field'] ?? array();

        if (!is_array($field)) {
            throw new InvalidRuleException('Validation field must be an object.');
        }

        $params = $data['params'] ?? array();

        if (!is_array($params)) {
            throw new InvalidRuleException('Validation params must be an object.');
        }

        return new self(
            (string) ($data['id'] ?? ''),
            FieldRef::fromArray($field),
            (string) ($data['type'] ?? ''),
            $params,
            (string) ($data['message'] ?? ''),
            (string) ($data['quantifier'] ?? ''),
        );
    }
}
