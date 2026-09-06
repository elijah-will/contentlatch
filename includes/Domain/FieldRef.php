<?php
/**
 * Canonical field identity plus UI snapshots.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

use ContentGuard\Domain\Exception\InvalidRuleException;

final class FieldRef
{
    public function __construct(
        public readonly string $key,
        public readonly string $name = '',
        public readonly string $label = '',
    ) {
        if ($this->key === '') {
            throw new InvalidRuleException('Field key is required.');
        }
    }

    /**
     * @return array{key: string, name: string, label: string}
     */
    public function toArray(): array
    {
        return array(
            'key'   => $this->key,
            'name'  => $this->name,
            'label' => $this->label,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['key'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['label'] ?? ''),
        );
    }
}
