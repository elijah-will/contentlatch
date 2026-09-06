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
}
