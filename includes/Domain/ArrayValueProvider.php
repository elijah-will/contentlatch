<?php
/**
 * In-memory field value provider for tests and fixtures.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain;

use ContentLatch\Domain\Contracts\FieldValueProviderInterface;

final class ArrayValueProvider implements FieldValueProviderInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function has(string $fieldId): bool
    {
        return array_key_exists($fieldId, $this->values);
    }

    public function get(string $fieldId): mixed
    {
        $value = $this->values[$fieldId] ?? null;
        if ($this->isInstanceList($value)) {
            return $value[0]->value ?? null;
        }

        return $value;
    }

    public function instances(string $fieldId): array
    {
        if (!$this->has($fieldId)) {
            return array();
        }

        $value = $this->values[$fieldId];
        if ($this->isInstanceList($value)) {
            return array_values($value);
        }

        return array(new FieldInstance($value));
    }

    private function isInstanceList(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if ($value === array()) {
            return true;
        }

        foreach ($value as $item) {
            if (!$item instanceof FieldInstance) {
                return false;
            }
        }

        return true;
    }
}
