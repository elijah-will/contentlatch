<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\RuleEditorDraftStore;
use PHPUnit\Framework\TestCase;

final class RuleEditorDraftStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testDraftKeysUseStablePrefixAndTtlRemainsFifteenMinutes(): void
    {
        $seen = array();
        $drafts = new RuleEditorDraftStore(
            static function (string $key, mixed $value, int $ttl) use (&$seen): void {
                $seen = array(
                    'key'   => $key,
                    'value' => $value,
                    'ttl'   => $ttl,
                );
            },
            static fn (string $key): mixed => null,
            static function (string $key): void {
            },
            static fn (): int => 7
        );

        $drafts->put(42, array('name' => 'Draft'));

        $this->assertSame(900, RuleEditorDraftStore::TTL);
        $this->assertSame('contentguard_rule_draft_', RuleEditorDraftStore::KEY_PREFIX);
        $this->assertSame('contentguard_rule_draft_7_42', $seen['key']);
        $this->assertSame(900, $seen['ttl']);
        $this->assertSame(array('name' => 'Draft'), $seen['value']);
    }

    public function testDeleteAllStoredRemovesTransientAndTimeoutOptionsByPrefix(): void
    {
        $wpdb = new class {
            public string $options = 'wp_options';

            /** @var array{sql: string, args: array<int, mixed>}|null */
            public ?array $last = null;

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            /**
             * @param mixed ...$args
             */
            public function prepare(string $sql, mixed ...$args): string
            {
                $this->last = array(
                    'sql'  => $sql,
                    'args' => $args,
                );

                return $sql;
            }

            public function query(string $sql): int
            {
                return 2;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        RuleEditorDraftStore::deleteAllStored();

        $this->assertNotNull($wpdb->last);
        $this->assertSame(
            'DELETE FROM wp_options WHERE option_name LIKE %s OR option_name LIKE %s',
            $wpdb->last['sql']
        );
        $this->assertSame(
            array(
                addcslashes('_transient_contentguard_rule_draft_', '_%\\') . '%',
                addcslashes('_transient_timeout_contentguard_rule_draft_', '_%\\') . '%',
            ),
            $wpdb->last['args']
        );
    }

    public function testDeleteAllStoredIsNoopWithoutWpdb(): void
    {
        unset($GLOBALS['wpdb']);
        RuleEditorDraftStore::deleteAllStored();
        $this->assertTrue(true);
    }

    public function testUninstallerDeletesRuleEditorDraftTransients(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Uninstaller.php');

        $this->assertStringContainsString('RuleEditorDraftStore::deleteAllStored', $src);
        $this->assertLessThan(
            strpos($src, 'RuleEditorDraftStore::deleteAllStored'),
            strpos($src, 'AuditSchema::drop')
        );
    }
}
