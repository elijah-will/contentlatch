<?php
/**
 * WordPress-backed storage for contentguard_rule posts.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

use ContentGuard\Application\Exception\RulePersistenceException;

final class WpRulePostStore implements RulePostStoreInterface
{
    public function insert(string $title, string $wpStatus, string $json, string $targetPostType): int
    {
        $id = wp_insert_post(
            array(
                'post_type'    => RulePostType::POST_TYPE,
                'post_title'   => $title,
                'post_status'  => $wpStatus,
                'post_content' => $json,
            ),
            true
        );

        if (is_wp_error($id) || !is_int($id) || $id <= 0) {
            $message = is_wp_error($id) ? $id->get_error_message() : 'Could not create rule.';
            throw new RulePersistenceException($message);
        }

        update_post_meta($id, RulePostType::TARGET_META_KEY, $targetPostType);

        return $id;
    }

    public function update(int $id, string $title, string $wpStatus, string $json, string $targetPostType): void
    {
        $result = wp_update_post(
            array(
                'ID'           => $id,
                'post_title'   => $title,
                'post_status'  => $wpStatus,
                'post_content' => $json,
            ),
            true
        );

        if (is_wp_error($result) || $result === 0) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'Could not update rule.';
            throw new RulePersistenceException($message);
        }

        update_post_meta($id, RulePostType::TARGET_META_KEY, $targetPostType);
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

    private function toRecord(\WP_Post $post): RulePostRecord
    {
        return new RulePostRecord(
            (int) $post->ID,
            (string) $post->post_title,
            (string) $post->post_status,
            (string) $post->post_content,
            (string) get_post_meta($post->ID, RulePostType::TARGET_META_KEY, true)
        );
    }
}
