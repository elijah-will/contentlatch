<?php
/**
 * ACF integration adapter. Wraps the existing catalog and stored provider
 * without changing Group/Repeater/Flexible/Clone behavior.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

defined('ABSPATH') || exit;

use ContentGuard\Application\Integration\FieldCatalog;
use ContentGuard\Application\Integration\Integration;
use ContentGuard\Dependencies;
use ContentGuard\Domain\Contracts\FieldValueProviderInterface;

final class AcfIntegration implements FieldCatalog
{
    public const ID    = Integration::ACF;
    public const LABEL = 'ACF';

    /**
     * @param callable(): bool|bool $available
     */
    public function __construct(
        private AcfFieldCatalog $catalog,
        private mixed $available = true,
    ) {
    }

    public static function wordpress(Dependencies $dependencies): self
    {
        return new self(
            new AcfFieldCatalog(),
            static fn (): bool => $dependencies->acfMeetsMinimum()
        );
    }

    public function descriptor(): Integration
    {
        return new Integration(self::ID, self::LABEL, $this->isAvailable());
    }

    public function isAvailable(): bool
    {
        if (is_callable($this->available)) {
            return (bool) ($this->available)();
        }

        return (bool) $this->available;
    }

    public function nativeCatalog(): AcfFieldCatalog
    {
        return $this->catalog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsForPostType(string $postType): array
    {
        $fields = array();

        foreach ($this->catalog->fieldsForPostType($postType) as $field) {
            if (!$field->isBuilderSelectable()) {
                continue;
            }

            $fields[] = array_merge($field->toCatalogArray(), array(
                'integration' => self::ID,
            ));
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    public function fieldTypesForPostType(string $postType): array
    {
        return $this->catalog->fieldTypesForPostType($postType);
    }

    /**
     * @param array<string, string> $fieldTypes
     * @param callable(string $fieldKey, int $postId): mixed|null $reader
     */
    public function storedProvider(
        int $postId,
        string $postType,
        array $fieldTypes,
        mixed $reader = null,
    ): FieldValueProviderInterface {
        $maps = $this->catalog->nestedResolutionMaps($postType, $fieldTypes);

        return new AcfStoredValueProvider(
            $postId,
            new AcfValueNormalizer(),
            $fieldTypes,
            $reader,
            $maps['paths'],
            $maps['names'],
            $maps['repeater_keys'],
            $maps['flex_keys'] ?? array(),
            $maps['layouts'] ?? array(),
            $maps['clone_keys'] ?? array(),
            $maps['repeater_chains'] ?? array()
        );
    }

    /**
     * @param array<string, string> $fieldTypes
     */
    public function incomingProvider(
        mixed $payload,
        string $postType,
        array $fieldTypes,
    ): FieldValueProviderInterface {
        $owned = array();
        foreach ($this->catalog->fieldTypesForPostType($postType) as $id => $type) {
            if (isset($fieldTypes[$id])) {
                $owned[$id] = $type;
            }
        }

        $maps = $this->catalog->nestedResolutionMaps($postType, $owned);

        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            $owned,
            $maps['paths'],
            $maps['names'],
            $maps['repeater_keys'],
            $maps['flex_keys'] ?? array(),
            $maps['layouts'] ?? array(),
            $maps['clone_keys'] ?? array(),
            $maps['repeater_chains'] ?? array()
        );
    }
}
