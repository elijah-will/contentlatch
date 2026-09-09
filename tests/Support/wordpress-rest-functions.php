<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        /**
         * @param mixed $data
         */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = '',
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(string $code = ''): mixed
        {
            unset($code);

            return $this->data;
        }
    }
}

if (!function_exists('get_post_field')) {
    /**
     * @param mixed $post
     */
    function get_post_field(string $field, mixed $post = null, string $context = 'display'): mixed
    {
        unset($context);
        $id = is_numeric($post) ? (int) $post : 0;

        return $GLOBALS['contentguard_test_post_fields'][$id][$field] ?? false;
    }
}

if (!function_exists('get_post_thumbnail_id')) {
    /**
     * @param mixed $post
     */
    function get_post_thumbnail_id(mixed $post = null): int
    {
        $id = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;

        return (int) ($GLOBALS['contentguard_test_thumbnails'][$id] ?? 0);
    }
}
