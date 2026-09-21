<?php
/**
 * Field values from an incoming ACF save payload (not the database).
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

defined('ABSPATH') || exit;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\FieldInstance;

final class AcfIncomingValueProvider implements FieldValueProviderInterface
{
    use AcfFieldKeyGuard;

    /**
     * @var array<string, mixed>
     */
    private array $payload;

    /**
     * @param mixed                 $payload    The ACF submission map (field_key => value), not $_POST.
     * @param array<string, string> $fieldTypes Canonical field key => ACF type from the catalog.
     * @param array<string, list<string>> $fieldPaths Trusted catalog paths keyed by leaf field key.
     * @param array<string, list<string>> $fieldPathNames Parallel field names for name-keyed payloads.
     * @param array<string, string> $repeaterKeys Leaf field key => Repeater field key.
     * @param array<string, string> $flexKeys Leaf field key => Flexible Content field key.
     * @param array<string, string> $layouts Leaf field key => trusted layout name.
     */
    public function __construct(
        mixed $payload,
        private AcfValueNormalizer $normalizer,
        private array $fieldTypes,
        private array $fieldPaths = array(),
        private array $fieldPathNames = array(),
        private array $repeaterKeys = array(),
        private array $flexKeys = array(),
        private array $layouts = array(),
        private array $cloneKeys = array(),
        private array $repeaterChains = array(),
    ) {
        $this->payload = is_array($payload) ? $payload : array();
    }

    public function has(string $fieldId): bool
    {
        if (!$this->isAllowedFieldKey($fieldId, $this->fieldTypes)) {
            return false;
        }

        $path = $this->pathFor($fieldId);
        if (count($path) > 1) {
            return AcfNestedField::read($this->payload, $path, $this->namesFor($fieldId))['found'];
        }

        return array_key_exists($fieldId, $this->payload);
    }

    public function get(string $fieldId): mixed
    {
        if (!$this->has($fieldId)) {
            return null;
        }

        $path = $this->pathFor($fieldId);
        $raw  = count($path) > 1
            ? AcfNestedField::read($this->payload, $path, $this->namesFor($fieldId))['value']
            : $this->payload[$fieldId];

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

        $pathToRepeater  = array_slice($path, 0, $repeaterIndex + 1);
        $namesToRepeater = array_slice($this->namesFor($fieldId), 0, $repeaterIndex + 1);
        $found           = AcfNestedField::read($this->payload, $pathToRepeater, $namesToRepeater);
        if (!$found['found']) {
            return array();
        }

        $childPath  = array_slice($path, $repeaterIndex + 1);
        $childNames = array_slice($this->namesFor($fieldId), $repeaterIndex + 1);
        $instances  = array();
        foreach (AcfNestedField::rows($found['value']) as $index => $entry) {
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

        $found = AcfNestedField::read(
            $this->payload,
            array_slice($path, 0, $outerIndex + 1),
            array_slice($names, 0, $outerIndex + 1)
        );
        if (!$found['found']) {
            return array();
        }

        $instances = array();
        foreach (AcfNestedField::nestedRepeaterCells($found['value'], $path, $names, $chain) as $cell) {
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

        $pathToFlex  = array_slice($path, 0, $flexIndex + 1);
        $namesToFlex = array_slice($this->namesFor($fieldId), 0, $flexIndex + 1);
        $found       = AcfNestedField::read($this->payload, $pathToFlex, $namesToFlex);
        if (!$found['found']) {
            return array();
        }

        $childPath  = array_slice($path, $flexIndex + 1);
        $childNames = array_slice($this->namesFor($fieldId), $flexIndex + 1);
        $instances  = array();

        foreach (AcfNestedField::rows($found['value']) as $index => $entry) {
            $row = $entry['row'];
            if (AcfNestedField::isDisabledFlexRow($row) || AcfNestedField::rowLayout($row) !== $layout) {
                continue;
            }

            $raw = is_array($row)
                ? AcfNestedField::walkStored($row, $childPath, $childNames)
                : null;
            $instances[] = new FieldInstance(
                $this->normalizer->normalize($raw, $this->fieldTypes[$fieldId]),
                array(
                    'row_key'     => $entry['key'],
                    'row_index'   => $index,
                    'display_row' => $index + 1,
                    'input_name'  => AcfNestedField::instanceInputName($path, $flexKey, $entry['key']),
                    'layout'      => $layout,
                )
            );
        }

        return $instances;
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
}
