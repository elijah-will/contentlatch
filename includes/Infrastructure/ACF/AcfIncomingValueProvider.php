<?php
/**
 * Field values from an incoming ACF save payload (not the database).
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;

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
     */
    public function __construct(
        mixed $payload,
        private AcfValueNormalizer $normalizer,
        private array $fieldTypes,
        private array $fieldPaths = array(),
        private array $fieldPathNames = array(),
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
