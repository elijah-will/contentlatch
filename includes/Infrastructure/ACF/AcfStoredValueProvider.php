<?php
/**
 * Field values from stored ACF post meta.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;

final class AcfStoredValueProvider implements FieldValueProviderInterface
{
    use AcfFieldKeyGuard;

    /**
     * @param array<string, string> $fieldTypes Canonical field key => ACF type from the catalog.
     * @param callable(string $fieldKey, int $postId): mixed|null $reader Optional test double for get_field().
     */
    public function __construct(
        private int $postId,
        private AcfValueNormalizer $normalizer,
        private array $fieldTypes,
        private mixed $reader = null,
    ) {
    }

    public function has(string $fieldId): bool
    {
        if (!$this->isAllowedFieldKey($fieldId, $this->fieldTypes)) {
            return false;
        }

        return $this->canRead();
    }

    public function get(string $fieldId): mixed
    {
        if (!$this->has($fieldId)) {
            return null;
        }

        return $this->normalizer->normalize(
            $this->read($fieldId),
            $this->fieldTypes[$fieldId]
        );
    }

    private function canRead(): bool
    {
        if (is_callable($this->reader)) {
            return true;
        }

        return function_exists('get_field');
    }

    private function read(string $fieldKey): mixed
    {
        if (is_callable($this->reader)) {
            return ($this->reader)($fieldKey, $this->postId);
        }

        if (!function_exists('get_field')) {
            return null;
        }

        return get_field($fieldKey, $this->postId, false);
    }
}
