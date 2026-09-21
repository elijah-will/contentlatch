<?php
/**
 * WordPress-backed storage for contentguard_rule posts.
 *
 * Canonical rule JSON lives in post meta. post_content keeps a slashed copy.
 * The CPT type is forced after write so reserved request keys such as
 * post_type cannot make find() miss a just-saved row.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentGuard\Application\Exception\RulePersistenceException;

final class WpRulePostStore implements RulePostStoreInterface
{
    public function insert(string $title, string $wpStatus, string $json, string $targetPostType): int
    {
        $id = $this->withoutContentKses(
            static function () use ($title, $wpStatus, $json): mixed {
                return wp_insert_post(
                    array(
                        'post_type'    => RulePostType::POST_TYPE,
                        'post_title'   => $title,
                        'post_status'  => $wpStatus,
                        'post_content' => RuleDocumentCodec::forPostContent($json),
                    ),
                    true
                );
            }
        );

        if (is_wp_error($id) || !is_int($id) || $id <= 0) {
            $message = is_wp_error($id) ? $id->get_error_message() : 'Could not create rule.';
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new RulePersistenceException($message);
        }

        $this->ensureRulePostType($id);
        $this->writeDocument($id, $json, $targetPostType);

        return $id;
    }

    public function update(int $id, string $title, string $wpStatus, string $json, string $targetPostType): void
    {
        $result = $this->withoutContentKses(
            static function () use ($id, $title, $wpStatus, $json): mixed {
                return wp_update_post(
                    array(
                        'ID'           => $id,
                        'post_type'    => RulePostType::POST_TYPE,
                        'post_title'   => $title,
                        'post_status'  => $wpStatus,
                        'post_content' => RuleDocumentCodec::forPostContent($json),
                    ),
                    true
                );
            }
        );

        if (is_wp_error($result) || $result === 0) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'Could not update rule.';
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new RulePersistenceException($message);
        }

        $this->ensureRulePostType($id);
        $this->writeDocument($id, $json, $targetPostType);
    }

    public function delete(int $id): bool
    {
        $deleted = wp_delete_post($id, true);

        return $deleted !== false && $deleted !== null;
    }

    public function get(int $id): ?RulePostRecord
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== RulePostType::POST_TYPE) {
            return null;
        }

        return $this->toRecord($post);
    }

    public function findByTargetPostType(string $targetPostType, bool $activeOnly): array
    {
        $args = array(
            'post_type'      => RulePostType::POST_TYPE,
            'post_status'    => $activeOnly ? array('publish') : array('publish', 'draft'),
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => RulePostType::TARGET_META_KEY,
            'meta_value'     => $targetPostType,
        );

        $posts = get_posts($args);
        if (!is_array($posts)) {
            return array();
        }

        $records = array();
        foreach ($posts as $post) {
            if ($post instanceof \WP_Post) {
                $records[] = $this->toRecord($post);
            }
        }

        return $records;
    }

    public function findAll(): array
    {
        $posts = get_posts(
            array(
                'post_type'      => RulePostType::POST_TYPE,
                'post_status'    => array('publish', 'draft'),
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        if (!is_array($posts)) {
            return array();
        }

        $records = array();
        foreach ($posts as $post) {
            if ($post instanceof \WP_Post) {
                $records[] = $this->toRecord($post);
            }
        }

        return $records;
    }

    public function findActiveTargetPostTypes(): array
    {
        $posts = get_posts(
            array(
                'post_type'      => RulePostType::POST_TYPE,
                'post_status'    => array('publish'),
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )
        );

        if (!is_array($posts)) {
            return array();
        }

        $types = array();
        foreach ($posts as $id) {
            $type = (string) get_post_meta((int) $id, RulePostType::TARGET_META_KEY, true);
            if ($type !== '') {
                $types[$type] = $type;
            }
        }

        return array_values($types);
    }

    /**
     * Safe read-back facts for WP_DEBUG. Never includes rule document bodies.
     *
     * @return array<string, mixed>
     */
    public function inspect(int $id): array
    {
        $post = function_exists('get_post') ? get_post($id) : null;
        $exists = is_object($post);
        $type = $exists && isset($post->post_type) ? (string) $post->post_type : '';
        $meta = $exists && function_exists('get_post_meta')
            ? get_post_meta($id, RulePostType::DOCUMENT_META_KEY, true)
            : null;
        $content = $exists && isset($post->post_content) ? (string) $post->post_content : '';
        $canonical = RuleDocumentCodec::fromRawStorage($meta);

        return array(
            'id'                 => $id,
            'post_exists'        => $exists,
            'post_type'          => $type,
            'expected_post_type' => RulePostType::POST_TYPE,
            'meta_present'       => is_string($meta) && $meta !== '',
            'meta_is_string'     => is_string($meta),
            'meta_is_document'   => $canonical !== null,
            'content_is_document'=> RuleDocumentCodec::fromRawStorage($content) !== null,
        );
    }

    private function writeDocument(int $id, string $json, string $targetPostType): void
    {
        $this->writeMeta($id, RulePostType::DOCUMENT_META_KEY, $json);
        $this->writeMeta($id, RulePostType::TARGET_META_KEY, $targetPostType);

        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }

        if (RuleDocumentCodec::fromStoredMeta(get_post_meta($id, RulePostType::DOCUMENT_META_KEY, true)) === null) {
            throw new RulePersistenceException('Could not store the rule document.');
        }
    }

    private function writeMeta(int $id, string $key, string $value): void
    {
        $payload = $key === RulePostType::DOCUMENT_META_KEY
            ? RuleDocumentCodec::forStoredMeta($value)
            : $value;

        $updated = update_post_meta($id, $key, $payload);
        if ($updated !== false) {
            return;
        }

        $existing = get_post_meta($id, $key, true);
        if (is_string($existing) && $existing === $value) {
            return;
        }

        if (
            $key === RulePostType::DOCUMENT_META_KEY
            && is_string($existing)
            && RuleDocumentCodec::isDocument($existing)
            && json_decode($existing, true) === json_decode($value, true)
        ) {
            return;
        }

        throw new RulePersistenceException('Could not store the rule document.');
    }

    private function ensureRulePostType(int $id): void
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            throw new RulePersistenceException('Could not create rule.');
        }

        if ($post->post_type === RulePostType::POST_TYPE) {
            return;
        }

        $updated = $this->withoutContentKses(
            static function () use ($id): mixed {
                return wp_update_post(
                    array(
                        'ID'        => $id,
                        'post_type' => RulePostType::POST_TYPE,
                    ),
                    true
                );
            }
        );

        if (is_wp_error($updated) || $updated === 0) {
            throw new RulePersistenceException('Could not create rule.');
        }

        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }

        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== RulePostType::POST_TYPE) {
            throw new RulePersistenceException('Could not create rule.');
        }
    }

    private function toRecord(\WP_Post $post): RulePostRecord
    {
        return new RulePostRecord(
            (int) $post->ID,
            (string) $post->post_title,
            (string) $post->post_status,
            $this->readDocument($post),
            (string) get_post_meta($post->ID, RulePostType::TARGET_META_KEY, true)
        );
    }

    private function readDocument(\WP_Post $post): string
    {
        $canonical = RuleDocumentCodec::fromRawStorage(
            get_post_meta($post->ID, RulePostType::DOCUMENT_META_KEY, true)
        );
        if ($canonical !== null) {
            return $canonical;
        }

        $content = (string) $post->post_content;
        $recovered = RuleDocumentCodec::fromRawStorage($content);
        if ($recovered === null) {
            return $content;
        }

        $this->healCanonicalMeta((int) $post->ID, $recovered);

        return $recovered;
    }

    private function healCanonicalMeta(int $id, string $json): void
    {
        try {
            $this->writeMeta($id, RulePostType::DOCUMENT_META_KEY, $json);
        } catch (RulePersistenceException) {
            return;
        }

        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }
    }

    /**
     * @template T
     * @param callable(): T $write
     * @return T
     */
    private function withoutContentKses(callable $write): mixed
    {
        $removed = function_exists('kses_remove_filters');
        if ($removed) {
            kses_remove_filters();
        }

        try {
            return $write();
        } finally {
            if ($removed && function_exists('kses_init_filters')) {
                kses_init_filters();
            }
        }
    }
}
