<?php
/**
 * Admin-post doubles for the audit CSV download tests.
 *
 * Namespaced functions replace PHP builtins only for ContentLatch\Admin,
 * which is the download controller. Production never loads this file.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support {
    use RuntimeException;

    final class AdminResponseEnded extends RuntimeException
    {
    }
}

namespace ContentLatch\Admin {
function header(string $header, bool $replace = true, int $response_code = 0): void
{
    unset($replace, $response_code);
    $GLOBALS['contentlatch_test_headers'][] = $header;
}

function headers_sent(?string &$file = null, ?int &$line = null): bool
{
    unset($file, $line);
    if (array_key_exists('contentlatch_test_headers_sent', $GLOBALS)) {
        return (bool) $GLOBALS['contentlatch_test_headers_sent'];
    }

    return \headers_sent();
}

/**
 * @param resource|null $context
 * @return resource|false
 */
function fopen(string $filename, string $mode, bool $use_include_path = false, $context = null)
{
    unset($use_include_path, $context);
    if (!empty($GLOBALS['contentlatch_test_fail_temp_open']) && str_starts_with($filename, 'php://temp')) {
        return false;
    }

    return \fopen($filename, $mode);
}

/**
 * @param resource $stream
 */
function fwrite($stream, string $data, ?int $length = null): int|false
{
    if (!empty($GLOBALS['contentlatch_test_fail_output_write']) && is_resource($stream)) {
        $meta = \stream_get_meta_data($stream);
        $uri  = is_array($meta) ? (string) ($meta['uri'] ?? '') : '';
        if (str_contains($uri, 'php://output')) {
            return false;
        }
    }

    return $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
}

function ob_end_clean(): bool
{
    if (!empty($GLOBALS['contentlatch_test_buffer_not_removable'])) {
        $GLOBALS['contentlatch_test_buffer_clean_attempts'] = (int) ($GLOBALS['contentlatch_test_buffer_clean_attempts'] ?? 0) + 1;
        if ((int) $GLOBALS['contentlatch_test_buffer_clean_attempts'] === 1) {
            return false;
        }
    }

    return \ob_end_clean();
}
}

namespace {
    if (!function_exists('wp_die')) {
        function wp_die(mixed $message = '', mixed $title = '', mixed $args = array()): void
        {
            $GLOBALS['contentlatch_test_wp_die'] = array(
                'message' => $message,
                'title'   => $title,
                'args'    => $args,
            );
        }
    }
}
