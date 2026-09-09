<?php
/**
 * Field values from stored WordPress post fields.
 *
 * Reads through public WordPress APIs. Does not query the database
 * directly and does not use presentation/filter title or excerpt helpers.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\FieldInstance;

final class CoreStoredValueProvider implements FieldValueProviderInterface
{
    /**
     * @param array<string, string> $fieldTypes Core resolution id => type from the catalog.
     * @param callable(string $fieldId, int $postId): mixed|null $reader Optional test double.
     */
    public function __construct(
        private int $postId,
        private array $fieldTypes,
        private CoreValueNormalizer $normalizer,
        private mixed $reader = null,
    ) {
    }

    public function has(string $fieldId): bool
    {
        if (!$this->isOwned($fieldId)) {
            return false;
        }

        return $this->canRead();
    }

    public function get(string $fieldId): mixed
    {
        if (!$this->has($fieldId)) {
            return null;
        }

        return $this->normalizer->normalize($fieldId, $this->read($fieldId));
    }

    public function instances(string $fieldId): array
    {
        if (!$this->has($fieldId)) {
            return array();
        }

        return array(new FieldInstance($this->get($fieldId)));
    }

    private function isOwned(string $fieldId): bool
    {
        return isset($this->fieldTypes[$fieldId]) && isset(CoreFieldCatalog::FIELDS[$fieldId]);
    }

    private function canRead(): bool
    {
        if (is_callable($this->reader)) {
            return true;
        }

        return function_exists('get_post_field');
    }

    private function read(string $fieldId): mixed
    {
        if (is_callable($this->reader)) {
            return ($this->reader)($fieldId, $this->postId);
        }

        return match ($fieldId) {
            CoreFieldCatalog::TITLE          => $this->postField('post_title'),
            CoreFieldCatalog::CONTENT        => $this->postField('post_content'),
            CoreFieldCatalog::EXCERPT        => $this->postField('post_excerpt'),
            CoreFieldCatalog::SLUG           => $this->postField('post_name'),
            CoreFieldCatalog::FEATURED_IMAGE => $this->thumbnailId(),
            CoreFieldCatalog::AUTHOR         => $this->postField('post_author'),
            default                          => null,
        };
    }

    private function postField(string $field): mixed
    {
        if (!function_exists('get_post_field')) {
            return null;
        }

        $value = get_post_field($field, $this->postId);

        return $value === false ? null : $value;
    }

    private function thumbnailId(): mixed
    {
        if (!function_exists('get_post_thumbnail_id')) {
            return 0;
        }

        return get_post_thumbnail_id($this->postId);
    }
}
