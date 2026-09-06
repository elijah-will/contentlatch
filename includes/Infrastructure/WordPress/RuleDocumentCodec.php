<?php
/**
 * Prepares and recovers rule JSON stored in WordPress post fields.
 *
 * update_post_meta() and wp_insert_post() unslash input. JSON that contains
 * escaped quotes or backslashes must be wp_slash()'d before those writes.
 * Reads try the raw string first and only unslash/recover when that is not
 * already a valid document.
 *
 * Canonical storage is post meta; post_content keeps a slashed copy.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

final class RuleDocumentCodec
{
    public static function isDocument(string $raw): bool
    {
        $data = json_decode($raw, true);

        return is_array($data) && isset($data['schema_version']);
    }

    public static function fromStoredMeta(mixed $meta): ?string
    {
        return self::fromRawStorage($meta);
    }

    /**
     * Decode a stored meta or post_content value without unslashing valid JSON.
     */
    public static function fromRawStorage(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (self::isDocument($value)) {
            return $value;
        }

        return self::recover($value);
    }

    /**
     * Prepare canonical JSON for update_post_meta(), which always wp_unslash()es.
     */
    public static function forStoredMeta(string $json): string
    {
        return function_exists('wp_slash') ? wp_slash($json) : addslashes($json);
    }

    public static function forPostContent(string $json): string
    {
        return function_exists('wp_slash') ? wp_slash($json) : $json;
    }

    public static function recover(string $raw): ?string
    {
        if (self::isDocument($raw)) {
            return $raw;
        }

        foreach (self::candidates($raw) as $candidate) {
            if (self::isDocument($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Legacy/malformed fallbacks only. The raw string is tried first by recover().
     *
     * @return list<string>
     */
    private static function candidates(string $raw): array
    {
        $candidates = array();

        $unslashed = stripslashes($raw);
        if ($unslashed !== $raw) {
            $candidates[] = $unslashed;
            $again = stripslashes($unslashed);
            if ($again !== $unslashed) {
                $candidates[] = $again;
            }
        }

        if (preg_match('/(\{.*\})/s', $raw, $matches) === 1) {
            $candidates[] = $matches[1];
            $stripped = stripslashes($matches[1]);
            if ($stripped !== $matches[1]) {
                $candidates[] = $stripped;
            }
        }

        return $candidates;
    }
}
