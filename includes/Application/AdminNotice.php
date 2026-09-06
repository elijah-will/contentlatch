<?php
/**
 * Success/error admin notices. Type and message stay paired.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

final class AdminNotice
{
    /**
     * @return array{contentguard_notice: string, contentguard_msg: string}
     */
    public static function queryArgs(bool $ok, string $message): array
    {
        return array(
            'contentguard_notice' => $ok ? 'success' : 'error',
            'contentguard_msg'    => $message,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array{type: 'success'|'error', message: string}|null
     */
    public static function fromQuery(array $query): ?array
    {
        if (!isset($query['contentguard_notice'], $query['contentguard_msg'])) {
            return null;
        }

        $typeRaw = self::unslash((string) $query['contentguard_notice']);
        $type    = self::normalizeType($typeRaw);
        $message = self::decodeMessage(self::unslash((string) $query['contentguard_msg']));

        if ($type === null || $message === '') {
            return null;
        }

        return array(
            'type'    => $type,
            'message' => $message,
        );
    }

    public static function cssClass(string $type): string
    {
        return $type === 'success'
            ? 'updated notice notice-success is-dismissible'
            : 'notice notice-error is-dismissible';
    }

    public static function decodeMessage(string $raw): string
    {
        $message = rawurldecode($raw);
        if (str_contains($message, '%')) {
            $again = rawurldecode($message);
            if ($again !== $message) {
                $message = $again;
            }
        }

        if (function_exists('sanitize_text_field')) {
            $message = sanitize_text_field($message);
        }

        return trim($message);
    }

    private static function normalizeType(string $type): ?string
    {
        $type = strtolower(trim($type));

        if (in_array($type, array('success', 'updated'), true)) {
            return 'success';
        }

        if ($type === 'error') {
            return 'error';
        }

        return null;
    }

    private static function unslash(string $value): string
    {
        return function_exists('wp_unslash') ? (string) wp_unslash($value) : $value;
    }
}
