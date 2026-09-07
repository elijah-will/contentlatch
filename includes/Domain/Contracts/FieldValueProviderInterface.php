<?php
/**
 * Reads a field value by canonical field key.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Contracts;

interface FieldValueProviderInterface
{
    public function has(string $fieldId): bool;

    public function get(string $fieldId): mixed;

    /**
     * Evaluation-time values for a field. Repeater children return one item per row.
     * Scalars return a single instance. Zero Repeater rows return an empty list.
     *
     * @return list<\ContentGuard\Domain\FieldInstance>
     */
    public function instances(string $fieldId): array;
}
