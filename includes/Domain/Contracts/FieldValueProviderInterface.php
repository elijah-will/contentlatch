<?php
/**
 * Reads a field value by canonical field key.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Contracts;

interface FieldValueProviderInterface
{
    public function has(string $fieldId): bool;

    public function get(string $fieldId): mixed;

    /**
     * Evaluation-time values for a field. Repeater and Flexible Content
     * children return one item per matching row. Scalars return a single
     * instance. Zero matching rows return an empty list.
     *
     * @return list<\ContentLatch\Domain\FieldInstance>
     */
    public function instances(string $fieldId): array;
}
