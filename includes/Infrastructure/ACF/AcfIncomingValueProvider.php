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
     * @param mixed                $payload    The ACF submission map (field_key => value), not $_POST.
     * @param array<string, string> $fieldTypes Canonical field key => ACF type from the catalog.
     */
    public function __construct(
        mixed $payload,
        private AcfValueNormalizer $normalizer,
        private array $fieldTypes,
    ) {
        $this->payload = is_array($payload) ? $payload : array();
    }

    public function has(string $fieldId): bool
    {
        if (!$this->isAllowedFieldKey($fieldId, $this->fieldTypes)) {
            return false;
        }

        return array_key_exists($fieldId, $this->payload);
    }

    public function get(string $fieldId): mixed
    {
        if (!$this->has($fieldId)) {
            return null;
        }

        return $this->normalizer->normalize(
            $this->payload[$fieldId],
            $this->fieldTypes[$fieldId]
        );
    }
}
