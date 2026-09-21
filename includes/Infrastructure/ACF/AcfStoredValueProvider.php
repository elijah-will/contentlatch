<?php
/**
 * Field values from stored ACF post meta.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

defined('ABSPATH') || exit;

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
     * @param array<string, string> $flexKeys Leaf field key => Flexible Content field key.
     * @param array<string, string> $layouts Leaf field key => trusted layout name.
     */
    public function __construct(
        private int $postId,
        private AcfValueNormalizer $normalizer,
        private array $fieldTypes,
        private mixed $reader = null,
        private array $fieldPaths = array(),
        private array $fieldPathNames = array(),
        private array $repeaterKeys = array(),
        private array $flexKeys = array(),
        private array $layouts = array(),
        private array $cloneKeys = array(),
        private array $repeaterChains = array(),
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

        $flexKey = $this->flexKeys[$fieldId] ?? '';
        if ($flexKey !== '') {
            return $this->flexInstances($fieldId, $flexKey);
        }

        $chain = $this->repeaterChainFor($fieldId);
        if (count($chain) > 1) {
            return $this->nestedRepeaterInstances($fieldId, $chain);
        }

        $repeaterKey = $chain[0] ?? '';
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
        $childPath  = array_slice($path, $repeaterIndex + 1);
        $childNames = array_slice($this->namesFor($fieldId), $repeaterIndex + 1);
        $rows       = AcfNestedField::rows($this->readRepeater($parentPath, $parentNames));

        $instances = array();
        foreach ($rows as $index => $entry) {
            $raw = AcfNestedField::walkStored($entry['row'], $childPath, $childNames);
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

    /**
     * @param list<string> $chain
     * @return list<FieldInstance>
     */
    private function nestedRepeaterInstances(string $fieldId, array $chain): array
    {
        $path = $this->pathFor($fieldId);
        $names = $this->namesFor($fieldId);
        $outerIndex = array_search($chain[0], $path, true);
        if ($outerIndex === false) {
            return array();
        }

        $outerValue = $this->readRepeater(
            array_slice($path, 0, $outerIndex + 1),
            array_slice($names, 0, $outerIndex + 1)
        );
        $instances = array();
        foreach (AcfNestedField::nestedRepeaterCells($outerValue, $path, $names, $chain) as $cell) {
            $instances[] = new FieldInstance(
                $this->normalizer->normalize($cell['value'], $this->fieldTypes[$fieldId]),
                AcfNestedField::nestedRepeaterContext($path, $chain, $cell['outer'], $cell['inner'])
            );
        }

        return $instances;
    }

    /**
     * @return list<string>
     */
    private function repeaterChainFor(string $fieldId): array
    {
        $chain = $this->repeaterChains[$fieldId] ?? array();
        if (is_array($chain) && $chain !== array()) {
            return array_values($chain);
        }

        $one = $this->repeaterKeys[$fieldId] ?? '';

        return $one !== '' ? array($one) : array();
    }

    /**
     * @return list<FieldInstance>
     */
    private function flexInstances(string $fieldId, string $flexKey): array
    {
        $layout = $this->layouts[$fieldId] ?? '';
        $path   = $this->pathFor($fieldId);
        if ($layout === '' || count($path) < 2) {
            return array();
        }

        $flexIndex = array_search($flexKey, $path, true);
        if ($flexIndex === false) {
            return array();
        }

        $rows = $this->read($flexKey);
        if (!is_array($rows)) {
            return array();
        }

        $childPath  = array_slice($path, $flexIndex + 1);
        $childNames = array_slice($this->namesFor($fieldId), $flexIndex + 1);
        $instances  = array();

        foreach ($rows as $index => $row) {
            if (!is_array($row) || AcfNestedField::rowLayout($row) !== $layout) {
                continue;
            }

            $rowKey = is_int($index) ? 'row-' . $index : (string) $index;
            if (!AcfNestedField::isSafeRowKey($rowKey)) {
                continue;
            }

            $rowIndex = is_int($index) ? $index : count($instances);
            $raw      = AcfNestedField::walkStored($row, $childPath, $childNames);
            $instances[] = new FieldInstance(
                $this->normalizer->normalize($raw, $this->fieldTypes[$fieldId]),
                array(
                    'row_key'     => $rowKey,
                    'row_index'   => $rowIndex,
                    'display_row' => $rowIndex + 1,
                    'input_name'  => AcfNestedField::instanceInputName($path, $flexKey, $rowKey),
                    'layout'      => $layout,
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

        if (($this->cloneKeys[$fieldId] ?? '') !== '') {
            return $this->readPrefixedCloneName($fieldId);
        }

        return $this->read($fieldId);
    }

    private function readPrefixedCloneName(string $fieldId): mixed
    {
        $names = $this->namesFor($fieldId);
        $name  = $names !== array() ? (string) $names[count($names) - 1] : '';
        if ($name === '') {
            return null;
        }

        return $this->read($name);
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
