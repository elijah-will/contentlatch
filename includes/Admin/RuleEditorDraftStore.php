<?php
/**
 * Per-user storage for an unsaved rule builder submission.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

defined('ABSPATH') || exit;

final class RuleEditorDraftStore
{
    public const TTL = 900;

    public const KEY_PREFIX = 'contentguard_rule_draft_';

    /**
     * @param callable(string $key, mixed $value, int $ttl): void $set
     * @param callable(string $key): mixed $get
     * @param callable(string $key): void $delete
     * @param callable(): int $userId
     */
    public function __construct(
        private mixed $set,
        private mixed $get,
        private mixed $delete,
        private mixed $userId,
        private int $ttl = self::TTL,
    ) {
    }

    public static function wordpress(): self
    {
        return new self(
            static function (string $key, mixed $value, int $ttl): void {
                if (function_exists('set_transient')) {
                    set_transient($key, $value, $ttl);
                }
            },
            static function (string $key): mixed {
                if (!function_exists('get_transient')) {
                    return null;
                }

                $value = get_transient($key);

                return $value === false ? null : $value;
            },
            static function (string $key): void {
                if (function_exists('delete_transient')) {
                    delete_transient($key);
                }
            },
            static function (): int {
                return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            }
        );
    }

    /**
     * Remove all ContentGuard rule-editor draft transients from the options table.
     *
     * WordPress stores set_transient() values as _transient_{name} and
     * _transient_timeout_{name}. There is no registry of draft keys, so uninstall
     * deletes by the stable key prefix owned by this store.
     */
    public static function deleteAllStored(): void
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->options) || !is_string($wpdb->options) || $wpdb->options === '') {
            return;
        }

        if (!method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')) {
            return;
        }

        $transientPrefix = '_transient_' . self::KEY_PREFIX;
        $timeoutPrefix   = '_transient_timeout_' . self::KEY_PREFIX;
        $like            = method_exists($wpdb, 'esc_like')
            ? $wpdb->esc_like($transientPrefix) . '%'
            : $transientPrefix . '%';
        $timeoutLike     = method_exists($wpdb, 'esc_like')
            ? $wpdb->esc_like($timeoutPrefix) . '%'
            : $timeoutPrefix . '%';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- No Core API deletes transient rows by key prefix; uninstall must clear ContentGuard draft keys.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Destructive uninstall cleanup must hit the options table directly.
        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table is $wpdb->options; LIKE values use esc_like + $wpdb->prepare %s.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->options} is the Core options table name, not user input.
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query string is built with $wpdb->prepare() immediately below.
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $timeoutLike
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function put(int $ruleId, array $snapshot): void
    {
        $set = $this->set;
        if (is_callable($set)) {
            $set($this->key($ruleId), $snapshot, $this->ttl);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $ruleId): ?array
    {
        $get = $this->get;
        if (!is_callable($get)) {
            return null;
        }

        $value = $get($this->key($ruleId));

        return is_array($value) ? $value : null;
    }

    public function forget(int $ruleId): void
    {
        $delete = $this->delete;
        if (is_callable($delete)) {
            $delete($this->key($ruleId));
        }
    }

    private function key(int $ruleId): string
    {
        $userId = is_callable($this->userId) ? (int) ($this->userId)() : 0;

        return self::KEY_PREFIX . $userId . '_' . max(0, $ruleId);
    }
}
