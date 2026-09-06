<?php
/**
 * Per-user storage for an unsaved rule builder submission.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

final class RuleEditorDraftStore
{
    public const TTL = 900;

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

        return 'contentguard_rule_draft_' . $userId . '_' . max(0, $ruleId);
    }
}
