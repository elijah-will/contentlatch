<?php
/**
 * WordPress Core integration adapter.
 *
 * Native post fields become ContentGuard catalog/provider values. RuleEngine
 * and FieldRef stay integration-agnostic.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

use ContentGuard\Application\Integration\FieldCatalog;
use ContentGuard\Application\Integration\Integration;
use ContentGuard\Domain\Contracts\FieldValueProviderInterface;

final class CoreIntegration implements FieldCatalog
{
    public const ID    = Integration::CORE;
    public const LABEL = 'WordPress';

    public function __construct(
        private CoreFieldCatalog $catalog = new CoreFieldCatalog(),
    ) {
    }

    public static function wordpress(): self
    {
        return new self(new CoreFieldCatalog());
    }

    public function descriptor(): Integration
    {
        return new Integration(self::ID, self::LABEL, $this->isAvailable());
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function nativeCatalog(): CoreFieldCatalog
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
            $fields[] = array_merge($field, array(
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
     * @param callable(string $fieldId, int $postId): mixed|null $reader
     */
    public function storedProvider(
        int $postId,
        string $postType,
        array $fieldTypes,
        mixed $reader = null,
    ): FieldValueProviderInterface {
        $owned = array();
        foreach ($this->catalog->fieldTypesForPostType($postType) as $id => $type) {
            if (isset($fieldTypes[$id])) {
                $owned[$id] = $type;
            }
        }

        return new CoreStoredValueProvider(
            $postId,
            $owned,
            new CoreValueNormalizer(),
            $reader
        );
    }
}
