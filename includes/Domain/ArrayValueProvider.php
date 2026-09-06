<?php
/**
 * In-memory field value provider for tests and fixtures.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;

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
        return $this->values[$fieldId] ?? null;
    }
}
