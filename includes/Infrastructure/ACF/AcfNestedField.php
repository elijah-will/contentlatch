<?php
/**
 * Trusted Group-child path helpers. Paths must come from the catalog or a
 * validated FieldRef — never from an arbitrary user-supplied string.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

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
