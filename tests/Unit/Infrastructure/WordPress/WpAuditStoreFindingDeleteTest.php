<?php
/**
 * Per-post finding deletes use a fixed statement.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Infrastructure\WordPress\AuditSchema;
use ContentLatch\Infrastructure\WordPress\WpAuditStore;
use PHPUnit\Framework\TestCase;

final class WpAuditStoreFindingDeleteTest extends TestCase
{
    private const DELETE_SQL = 'DELETE FROM %i WHERE run_id = %d AND post_id = %d';

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testEmptyIdListDoesNotQuery(): void
    {
        $wpdb = $this->installWpdb();
        $store = new WpAuditStore();

        $store->replaceFindingsForPosts(9, array(), array());

        $this->assertSame(array(), $wpdb->calls);
        $this->assertSame(array(), $wpdb->inserts);
    }

    public function testZeroAndNonNumericIdsAreDropped(): void
    {
        $wpdb = $this->installWpdb();
        $store = new WpAuditStore();

        $store->replaceFindingsForPosts(9, array(0, '0', 'nope', ''), array());

        $this->assertSame(array(), $wpdb->calls);
    }

    public function testOneIdUsesPlaceholders(): void
    {
        $wpdb = $this->installWpdb();
        $store = new WpAuditStore();

        $store->replaceFindingsForPosts(9, array(42), array());

        $this->assertSame(
            array(
                array(
                    'sql'  => self::DELETE_SQL,
                    'args' => array(AuditSchema::findingsTable(), 9, 42),
                ),
            ),
            $wpdb->calls
        );
        $this->assertStringNotContainsString('42', $wpdb->calls[0]['sql']);
    }

    public function testMultipleIdsEachUseTheSameStatement(): void
    {
        $wpdb = $this->installWpdb();
        $store = new WpAuditStore();

        $store->replaceFindingsForPosts(9, array('15', -4, 15), array());

        $this->assertSame(
            array(
                array(
                    'sql'  => self::DELETE_SQL,
                    'args' => array(AuditSchema::findingsTable(), 9, 15),
                ),
                array(
                    'sql'  => self::DELETE_SQL,
                    'args' => array(AuditSchema::findingsTable(), 9, -4),
                ),
                array(
                    'sql'  => self::DELETE_SQL,
                    'args' => array(AuditSchema::findingsTable(), 9, 15),
                ),
            ),
            $wpdb->calls
        );
        foreach ($wpdb->calls as $call) {
            $this->assertStringNotContainsString('IN (', $call['sql']);
            $this->assertDoesNotMatchRegularExpression('/-?\d{2,}/', $call['sql']);
        }
    }

    public function testInsertStillFollowsDeletes(): void
    {
        $wpdb = $this->installWpdb();
        $store = new WpAuditStore();
        $finding = new AuditFinding(
            0,
            9,
            42,
            'recipe',
            3,
            'content',
            'required',
            'missing',
            RuleSeverity::Fail,
            'Required.',
            '2026-01-01 00:00:00'
        );

        $store->replaceFindingsForPosts(9, array(42), array($finding));

        $this->assertCount(1, $wpdb->calls);
        $this->assertSame(array(AuditSchema::findingsTable(), 9, 42), $wpdb->calls[0]['args']);
        $this->assertSame(
            array(
                array(
                    'table'  => AuditSchema::findingsTable(),
                    'postId' => 42,
                    'runId'  => 9,
                ),
            ),
            $wpdb->inserts
        );
    }

    public function testDeleteSqlIsNotBuiltFromAPlaceholderList(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/WordPress/WpAuditStore.php');
        $method = substr($src, (int) strpos($src, 'function replaceFindingsForPosts'));
        $method = substr($method, 0, (int) strpos($method, 'function findFindings'));

        $this->assertStringNotContainsString('array_fill', $method);
        $this->assertStringNotContainsString('IN (', $method);
        $this->assertStringContainsString('post_id = %d', $method);
    }

    private function installWpdb(): object
    {
        $wpdb = new class {
            public string $prefix = 'wp_';

            /** @var list<array{sql: string, args: array<int, mixed>}> */
            public array $calls = array();

            /** @var list<array{table: string, postId: int, runId: int}> */
            public array $inserts = array();

            /**
             * @param mixed ...$args
             */
            public function prepare(string $sql, mixed ...$args): string
            {
                $this->calls[] = array(
                    'sql'  => $sql,
                    'args' => $args,
                );

                return $sql;
            }

            public function query(string $sql): int
            {
                return 1;
            }

            /**
             * @param array<string, mixed> $data
             * @param array<int, string>   $format
             */
            public function insert(string $table, array $data, array $format): int
            {
                $this->inserts[] = array(
                    'table'  => $table,
                    'postId' => (int) $data['post_id'],
                    'runId'  => (int) $data['run_id'],
                );

                return 1;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }
}
