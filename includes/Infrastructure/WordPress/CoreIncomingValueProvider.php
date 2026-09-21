<?php
/**
 * Field values from a submitted WordPress Core payload (not the database).
 *
 * Accepts Core resolution ids and WordPress post-field aliases. Does not
 * read request superglobals and does not query stored post values.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\FieldInstance;

final class CoreIncomingValueProvider implements FieldValueProviderInterface
{
    /**
     * Payload keys accepted for each Core id. Core ids are preferred when
     * both a Core id and a WordPress alias are present.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = array(
        CoreFieldCatalog::TITLE          => array('title', 'post_title'),
        CoreFieldCatalog::CONTENT        => array('content', 'post_content'),
        CoreFieldCatalog::EXCERPT        => array('excerpt', 'post_excerpt'),
        CoreFieldCatalog::SLUG           => array('slug', 'post_name'),
        CoreFieldCatalog::FEATURED_IMAGE => array(
            'featured_image',
            '_thumbnail_id',
            'thumbnail_id',
            'featured_media',
        ),
        CoreFieldCatalog::AUTHOR         => array('author', 'post_author'),
    );

    /**
     * @var array<string, mixed>
     */
    private array $payload;

    /**
     * @param mixed                 $payload    Submitted Core values, not the request superglobal.
     * @param array<string, string> $fieldTypes Core resolution id => type from the catalog.
     */
    public function __construct(
        mixed $payload,
        private array $fieldTypes,
        private CoreValueNormalizer $normalizer,
    ) {
        $this->payload = is_array($payload) ? $payload : array();
    }

    public function has(string $fieldId): bool
    {
        if (!$this->isOwned($fieldId)) {
            return false;
        }

        return $this->payloadKey($fieldId) !== null;
    }

    public function get(string $fieldId): mixed
    {
        if (!$this->has($fieldId)) {
            return null;
        }

        $key = $this->payloadKey($fieldId);
        if ($key === null) {
            return null;
        }

        return $this->normalizer->normalize(
            $fieldId,
            CoreIncomingPayload::unwrapRestValue($this->payload[$key])
        );
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

    private function payloadKey(string $fieldId): ?string
    {
        $fallback = null;

        foreach (self::ALIASES[$fieldId] ?? array($fieldId) as $key) {
            if (!array_key_exists($key, $this->payload)) {
                continue;
            }

            $value = $this->payload[$key];
            if (CoreIncomingPayload::isEmptyRestPlaceholder($value)) {
                $fallback ??= $key;
                continue;
            }

            return $key;
        }

        return $fallback;
    }
}
