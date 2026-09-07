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
     * @param array<string, list<string>> $fieldPaths Trusted catalog paths keyed by leaf field key.
     * @param array<string, list<string>> $fieldPathNames Parallel field names for stored-value fallback.
     */
    public function __construct(
        private int $postId,
        private AcfValueNormalizer $normalizer,
        private array $fieldTypes,
        private mixed $reader = null,
        private array $fieldPaths = array(),
        private array $fieldPathNames = array(),
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

        $raw = $this->isNested($fieldId)
            ? $this->resolveNested($fieldId)
            : $this->read($fieldId);

        return $this->normalizer->normalize(
            $raw,
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

    /**
     * Group children are stored under the parent Group (prefixed meta /
     * Group load_value), not under the leaf field name. get_field($leafKey)
     * looks up the unprefixed name and ACF then applies default_value (''
     * for textarea). That empty default must not hide the real Group value.
     */
    private function resolveNested(string $fieldId): mixed
    {
        $fromPath = $this->readNested($fieldId);
        if (!$this->isUnavailable($fromPath)) {
            return $fromPath;
        }

        return $this->read($fieldId);
    }

    private function readNested(string $fieldId): mixed
    {
        $path  = $this->fieldPaths[$fieldId];
        $names = $this->fieldPathNames[$fieldId] ?? array();
        $parent = $this->read($path[0]);

        return AcfNestedField::walkStored(
            $parent,
            array_slice($path, 1),
            is_array($names) ? array_slice($names, 1) : array()
        );
    }

    private function isNested(string $fieldId): bool
    {
        $path = $this->fieldPaths[$fieldId] ?? array();

        return is_array($path) && count($path) > 1;
    }

    private function isUnavailable(mixed $value): bool
    {
        return $value === null || $value === false;
    }
}
