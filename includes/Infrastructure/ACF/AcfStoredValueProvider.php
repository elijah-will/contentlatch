<?php
/**
 * Field values from stored ACF post meta.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\FieldInstance;

final class AcfStoredValueProvider implements FieldValueProviderInterface
{
    use AcfFieldKeyGuard;

    /**
     * @param array<string, string> $fieldTypes Canonical field key => ACF type from the catalog.
     * @param callable(string $fieldKey, int $postId): mixed|null $reader Optional test double for get_field().
     * @param array<string, list<string>> $fieldPaths Trusted catalog paths keyed by leaf field key.
     * @param array<string, list<string>> $fieldPathNames Parallel field names for stored-value fallback.
     * @param array<string, string> $repeaterKeys Leaf field key => Repeater field key.
     */
    public function __construct(
        private int $postId,
        private AcfValueNormalizer $normalizer,
        private array $fieldTypes,
        private mixed $reader = null,
        private array $fieldPaths = array(),
        private array $fieldPathNames = array(),
        private array $repeaterKeys = array(),
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

    public function instances(string $fieldId): array
    {
        if (!$this->isAllowedFieldKey($fieldId, $this->fieldTypes)) {
            return array();
        }

        $repeaterKey = $this->repeaterKeys[$fieldId] ?? '';
        $path        = $this->pathFor($fieldId);
        if ($repeaterKey === '' || count($path) < 2) {
            if (!$this->has($fieldId)) {
                return array();
            }

            return array(new FieldInstance($this->get($fieldId)));
        }

        $repeaterIndex = array_search($repeaterKey, $path, true);
        if ($repeaterIndex === false) {
            return array();
        }

        $parentPath  = array_slice($path, 0, $repeaterIndex + 1);
        $parentNames = array_slice($this->namesFor($fieldId), 0, $repeaterIndex + 1);
        $childName   = $this->namesFor($fieldId)[count($path) - 1] ?? '';
        $rows        = AcfNestedField::rows($this->readRepeater($parentPath, $parentNames));

        $instances = array();
        foreach ($rows as $index => $entry) {
            $raw = AcfNestedField::rowChild($entry['row'], $fieldId, $childName);
            $instances[] = new FieldInstance(
                $this->normalizer->normalize($raw, $this->fieldTypes[$fieldId]),
                array(
                    'row_key'     => $entry['key'],
                    'row_index'   => $index,
                    'display_row' => $index + 1,
                    'input_name'  => AcfNestedField::instanceInputName($path, $repeaterKey, $entry['key']),
                )
            );
        }

        return $instances;
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

    /**
     * @return list<string>
     */
    private function pathFor(string $fieldId): array
    {
        $path = $this->fieldPaths[$fieldId] ?? array();

        return is_array($path) ? $path : array();
    }

    /**
     * @return list<string>
     */
    private function namesFor(string $fieldId): array
    {
        $names = $this->fieldPathNames[$fieldId] ?? array();

        return is_array($names) ? $names : array();
    }

    /**
     * @param list<string> $parentPath
     * @param list<string> $parentNames
     */
    private function readRepeater(array $parentPath, array $parentNames): mixed
    {
        $root = $this->read($parentPath[0]);
        if (count($parentPath) === 1) {
            return $root;
        }

        return AcfNestedField::walkStored(
            $root,
            array_slice($parentPath, 1),
            array_slice($parentNames, 1)
        );
    }
}
