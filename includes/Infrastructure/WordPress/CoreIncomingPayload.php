<?php
/**
 * Extracts submitted Core field values from a WordPress save request.
 *
 * Does not generate slugs, query the database, or invent omitted fields.
 * An empty result means this request did not include Core values.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class CoreIncomingPayload
{
    /**
     * Request keys accepted as Core incoming values.
     *
     * @var list<string>
     */
    private const REQUEST_KEYS = array(
        'title',
        'post_title',
        'content',
        'post_content',
        'excerpt',
        'post_excerpt',
        'slug',
        'post_name',
        'featured_image',
        '_thumbnail_id',
        'thumbnail_id',
        'featured_media',
        'author',
        'post_author',
    );

    /**
     * Prepared-post properties mapped to Core incoming keys.
     *
     * @var array<string, string>
     */
    private const PREPARED_PROPERTIES = array(
        'post_title'   => 'post_title',
        'post_content' => 'post_content',
        'post_excerpt' => 'post_excerpt',
        'post_name'    => 'post_name',
        'post_author'  => 'post_author',
    );

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    public static function fromRequest(array $request): ?array
    {
        $payload = array();
        foreach (self::REQUEST_KEYS as $key) {
            if (array_key_exists($key, $request)) {
                $payload[$key] = $request[$key];
            }
        }

        return $payload === array() ? null : $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fromPreparedPost(object $prepared, mixed $request): ?array
    {
        $payload = array();
        foreach (self::PREPARED_PROPERTIES as $property => $key) {
            if (property_exists($prepared, $property)) {
                $payload[$key] = $prepared->{$property};
            }
        }

        $json = self::jsonParams($request);
        if (is_array($json)) {
            $fromJson = self::fromRequest($json);
            if ($fromJson !== null) {
                $payload = array_merge($payload, $fromJson);
            }
        }

        $fromRequest = array(
            'title'   => self::restTextField($request, 'title'),
            'content' => self::restTextField($request, 'content'),
            'excerpt' => self::restTextField($request, 'excerpt'),
            'slug'    => self::restTextField($request, 'slug'),
            'author'  => self::requestValue($request, 'author'),
        );
        foreach ($fromRequest as $key => $value) {
            if ($value !== null && !self::isEmptyRestPlaceholder($value)) {
                $payload[$key] = $value;
            }
        }

        $featured = self::requestValue($request, 'featured_media');
        if ($featured === null) {
            $featured = self::requestValue($request, 'featured_image');
        }
        if ($featured !== null) {
            $payload['featured_media'] = $featured;
        }

        return $payload === array() ? null : $payload;
    }

    /**
     * Gutenberg sends title/content/excerpt as a raw string or as
     * { raw, rendered, protected, block_version }. Arrays without a usable
     * scalar are placeholders (schema sanitization), not submitted text.
     */
    public static function unwrapRestValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('raw', $value)) {
            $raw = $value['raw'];
            if (is_scalar($raw) || $raw === null) {
                return $raw === null ? '' : (string) $raw;
            }
        }

        if (is_object($value) && property_exists($value, 'raw') && (is_scalar($value->raw) || $value->raw === null)) {
            return $value->raw === null ? '' : (string) $value->raw;
        }

        if (is_array($value) && array_key_exists('rendered', $value) && is_scalar($value['rendered'])) {
            return (string) $value['rendered'];
        }

        if (is_object($value) && isset($value->rendered) && is_scalar($value->rendered)) {
            return (string) $value->rendered;
        }

        return $value;
    }

    public static function isEmptyRestPlaceholder(mixed $value): bool
    {
        return $value === array()
            || ($value instanceof \stdClass && get_object_vars($value) === array());
    }

    /**
     * Gutenberg sends title/content/excerpt as { raw, rendered }. WordPress
     * prepare_item_for_database uses ! empty() on raw, so a cleared title is
     * omitted from the prepared post. Empty raw must still be submitted.
     */
    public static function restTextField(mixed $request, string $key): mixed
    {
        $value = self::requestValue($request, $key);
        if ($value === null || self::isEmptyRestPlaceholder($value)) {
            return null;
        }

        $unwrapped = self::unwrapRestValue($value);
        if (is_string($unwrapped) || is_numeric($unwrapped)) {
            return (string) $unwrapped;
        }

        return null;
    }

    public static function requestValue(mixed $request, string $key): mixed
    {
        $json = self::jsonParams($request);
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }

        if (is_array($request) && array_key_exists($key, $request)) {
            return $request[$key];
        }

        if (is_object($request) && method_exists($request, 'has_param') && $request->has_param($key)
            && method_exists($request, 'get_param')) {
            return $request->get_param($key);
        }

        if ($request instanceof \ArrayAccess && $request->offsetExists($key)) {
            return $request[$key];
        }

        if (is_object($request) && method_exists($request, 'get_param')) {
            return $request->get_param($key);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function jsonParams(mixed $request): ?array
    {
        if (!is_object($request) || !method_exists($request, 'get_json_params')) {
            return null;
        }

        $json = $request->get_json_params();

        return is_array($json) ? $json : null;
    }

    public static function requestRoute(mixed $request): string
    {
        if (is_object($request) && method_exists($request, 'get_route')) {
            return (string) $request->get_route();
        }

        if (is_array($request) && isset($request['route']) && is_scalar($request['route'])) {
            return (string) $request['route'];
        }

        return '';
    }
}
