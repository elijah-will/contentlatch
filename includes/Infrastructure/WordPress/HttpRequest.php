<?php
/**
 * WordPress HTTP request helpers.
 *
 * WordPress magic-quotes slashes $_GET/$_POST/$_REQUEST/$_COOKIE. Values that
 * originated there must be unslashed once at the HTTP boundary before they
 * are stored or evaluated. Do not unslash already-clean in-memory data.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class HttpRequest
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function unslash(array $request): array
    {
        if (!function_exists('wp_unslash')) {
            return $request;
        }

        $unslashed = wp_unslash($request);

        return is_array($unslashed) ? $unslashed : $request;
    }
}
