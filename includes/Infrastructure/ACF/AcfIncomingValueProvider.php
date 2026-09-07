<?php
/**
 * Field values from an incoming ACF save payload (not the database).
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

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
     */
    public function __construct(
        mixed $payload,
        private AcfValueNormalizer $normalizer,
        private array $fieldTypes,
        private array $fieldPaths = array(),
        private array $fieldPathNames = array(),
        private array $repeaterKeys = array(),
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

        $pathToRepeater  = array_slice($path, 0, $repeaterIndex + 1);
        $namesToRepeater = array_slice($this->namesFor($fieldId), 0, $repeaterIndex + 1);
        $found           = AcfNestedField::read($this->payload, $pathToRepeater, $namesToRepeater);
        if (!$found['found']) {
            return array();
        }

        $childName = $this->namesFor($fieldId)[count($path) - 1] ?? '';
        $instances = array();
        foreach (AcfNestedField::rows($found['value']) as $index => $entry) {
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
