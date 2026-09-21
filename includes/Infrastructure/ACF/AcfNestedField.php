<?php
/**
 * Trusted Group-child path helpers. Paths must come from the catalog or a
 * validated FieldRef — never from an arbitrary user-supplied string.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

defined('ABSPATH') || exit;

final class AcfNestedField
{
    /**
     * ACF 6 Group children use the prepared parent input name as prefix, so
     * the posted name is acf[field_group][field_child] (and deeper for nested
     * Groups). acf_add_validation_error() matches that same name.
     *
     * @param list<string> $path
     */
    public static function inputName(string $leafKey, array $path = array()): string
    {
        $segments = $path !== array() ? $path : array($leafKey);
        $name     = 'acf';

        foreach ($segments as $segment) {
            $name .= '[' . $segment . ']';
        }

        return $name;
    }

    /**
     * Insert the live row key after the Repeater segment.
     *
     * @param list<string> $path
     */
    public static function instanceInputName(array $path, string $repeaterKey, string $rowKey): string
    {
        $rows = array();
        if ($repeaterKey !== '' && $rowKey !== '') {
            $rows[$repeaterKey] = $rowKey;
        }

        return self::instanceInputNameForRepeaters($path, $rows);
    }

    /**
     * Insert a live row key after each Repeater segment that has one.
     *
     * @param list<string>         $path
     * @param array<string, string> $rowKeysByRepeater Repeater field key => row key.
     */
    public static function instanceInputNameForRepeaters(array $path, array $rowKeysByRepeater): string
    {
        $name = 'acf';

        foreach ($path as $segment) {
            $name .= '[' . $segment . ']';
            $rowKey = $rowKeysByRepeater[$segment] ?? '';
            if ($rowKey !== '') {
                $name .= '[' . $rowKey . ']';
            }
        }

        return $name;
    }

    /**
     * Expand exactly two Repeater levels into cells. An empty or missing
     * inner Repeater contributes no cells for that outer row.
     *
     * @param list<string> $path
     * @param list<string> $names
     * @param list<string> $chain [outerRepeaterKey, innerRepeaterKey]
     * @return list<array{value: mixed, outer: array{key: string, index: int}, inner: array{key: string, index: int}}>
     */
    public static function nestedRepeaterCells(mixed $outerValue, array $path, array $names, array $chain): array
    {
        if (count($chain) !== 2) {
            return array();
        }

        $outerIndex = array_search($chain[0], $path, true);
        $innerIndex = array_search($chain[1], $path, true);
        if ($outerIndex === false || $innerIndex === false || $innerIndex <= $outerIndex) {
            return array();
        }

        $innerRelPath  = array_slice($path, $outerIndex + 1, $innerIndex - $outerIndex);
        $innerRelNames = array_slice($names, $outerIndex + 1, $innerIndex - $outerIndex);
        $leafPath      = array_slice($path, $innerIndex + 1);
        $leafNames     = array_slice($names, $innerIndex + 1);
        $cells         = array();

        foreach (self::rows($outerValue) as $outerIdx => $outerEntry) {
            $innerValue = self::walkStored($outerEntry['row'], $innerRelPath, $innerRelNames);
            foreach (self::rows($innerValue) as $innerIdx => $innerEntry) {
                $raw = $leafPath === array()
                    ? $innerEntry['row']
                    : self::walkStored($innerEntry['row'], $leafPath, $leafNames);
                $cells[] = array(
                    'value' => $raw,
                    'outer' => array(
                        'key'   => $outerEntry['key'],
                        'index' => $outerIdx,
                    ),
                    'inner' => array(
                        'key'   => $innerEntry['key'],
                        'index' => $innerIdx,
                    ),
                );
            }
        }

        return $cells;
    }

    /**
     * Nested Repeater coordinates. Does not set one-level display_row.
     *
     * @param list<string>                    $path
     * @param list<string>                    $chain
     * @param array{key: string, index: int}  $outer
     * @param array{key: string, index: int}  $inner
     * @return array<string, mixed>
     */
    public static function nestedRepeaterContext(array $path, array $chain, array $outer, array $inner): array
    {
        return array(
            'input_name'    => self::instanceInputNameForRepeaters($path, array(
                $chain[0] => $outer['key'],
                $chain[1] => $inner['key'],
            )),
            'repeater_rows' => array(
                array(
                    'repeater'    => $chain[0],
                    'key'         => $outer['key'],
                    'index'       => $outer['index'],
                    'display_row' => $outer['index'] + 1,
                ),
                array(
                    'repeater'    => $chain[1],
                    'key'         => $inner['key'],
                    'index'       => $inner['index'],
                    'display_row' => $inner['index'] + 1,
                ),
            ),
        );
    }

    /**
     * Target the Repeater container (zero-row failures).
     *
     * @param list<string> $path
     */
    public static function repeaterInputName(array $path, string $repeaterKey): string
    {
        $name = 'acf';

        foreach ($path as $segment) {
            $name .= '[' . $segment . ']';
            if ($repeaterKey !== '' && $segment === $repeaterKey) {
                break;
            }
        }

        return $name;
    }

    public static function isSafeRowKey(string $key): bool
    {
        if ($key === '' || $key === 'acfcloneindex') {
            return false;
        }

        return (bool) preg_match('/^(row-\d+|[A-Za-z0-9_-]+)$/', $key);
    }

    public static function rowLayout(mixed $row): string
    {
        if (!is_array($row)) {
            return '';
        }

        return (string) ($row['acf_fc_layout'] ?? '');
    }

    public static function isDisabledFlexRow(mixed $row): bool
    {
        return is_array($row) && !empty($row['acf_fc_layout_disabled']);
    }

    /**
     * @return list<array{key: string, row: mixed}>
     */
    public static function rows(mixed $repeaterValue): array
    {
        if (!is_array($repeaterValue)) {
            return array();
        }

        $rows = array();
        foreach ($repeaterValue as $key => $row) {
            $rowKey = is_int($key) ? 'row-' . $key : (string) $key;
            if (!self::isSafeRowKey($rowKey)) {
                continue;
            }

            $rows[] = array(
                'key' => $rowKey,
                'row' => $row,
            );
        }

        return $rows;
    }

    public static function rowChild(mixed $row, string $childKey, string $childName = ''): mixed
    {
        if (!is_array($row)) {
            return null;
        }

        if (array_key_exists($childKey, $row)) {
            return $row[$childKey];
        }

        if ($childName !== '' && array_key_exists($childName, $row)) {
            return $row[$childName];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload The ACF submission map (field_key => value).
     * @param list<string>         $path
     * @param list<string>         $names Parallel field names when a level is name-keyed.
     * @return array{found: bool, value: mixed}
     */
    public static function read(array $payload, array $path, array $names = array()): array
    {
        if ($path === array()) {
            return array(
                'found' => false,
                'value' => null,
            );
        }

        $current = $payload;
        $last    = count($path) - 1;

        foreach ($path as $i => $key) {
            $name = $names[$i] ?? '';
            if (!is_array($current)) {
                return array(
                    'found' => false,
                    'value' => null,
                );
            }

            if (array_key_exists($key, $current)) {
                $value = $current[$key];
            } elseif ($name !== '' && array_key_exists($name, $current)) {
                $value = $current[$name];
            } else {
                return array(
                    'found' => false,
                    'value' => null,
                );
            }

            if ($i === $last) {
                return array(
                    'found' => true,
                    'value' => $value,
                );
            }

            $current = $value;
        }

        return array(
            'found' => false,
            'value' => null,
        );
    }

    /**
     * Walk a stored Group value. ACF Group load_value() keys children by field
     * key; formatted values may use field names.
     *
     * @param list<string> $keys
     * @param list<string> $names
     */
    public static function walkStored(mixed $current, array $keys, array $names = array()): mixed
    {
        foreach ($keys as $i => $key) {
            if (!is_array($current)) {
                return null;
            }

            $name = $names[$i] ?? '';
            if (array_key_exists($key, $current)) {
                $current = $current[$key];
            } elseif ($name !== '' && array_key_exists($name, $current)) {
                $current = $current[$name];
            } else {
                return null;
            }
        }

        return $current;
    }
}
