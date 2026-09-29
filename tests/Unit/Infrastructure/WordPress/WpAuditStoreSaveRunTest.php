<?php
/**
 * Fixed UPDATE statements for nullable audit-run columns.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

require_once dirname(__DIR__, 3) . '/Support/wordpress-admin-functions.php';

use ContentLatch\Application\Audit\AuditRun;
use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Infrastructure\WordPress\AuditSchema;
use ContentLatch\Infrastructure\WordPress\WpAuditStore;
use PHPUnit\Framework\TestCase;

final class WpAuditStoreSaveRunTest extends TestCase
{
    private const SQL_BOTH_NULL = 'UPDATE %i SET status = %s, finished_at = NULL, heartbeat_at = %s, posts_scanned = %d, posts_passed = %d, posts_warned = %d, posts_failed = %d, posts_not_evaluated = %d, posts_total = %d, scan_cursor = %d, actor_user_id = %d, post_types = %s, error_message = NULL WHERE id = %d';

    private const SQL_FINISHED = 'UPDATE %i SET status = %s, finished_at = %s, heartbeat_at = %s, posts_scanned = %d, posts_passed = %d, posts_warned = %d, posts_failed = %d, posts_not_evaluated = %d, posts_total = %d, scan_cursor = %d, actor_user_id = %d, post_types = %s, error_message = NULL WHERE id = %d';

    private const SQL_ERROR = 'UPDATE %i SET status = %s, finished_at = NULL, heartbeat_at = %s, posts_scanned = %d, posts_passed = %d, posts_warned = %d, posts_failed = %d, posts_not_evaluated = %d, posts_total = %d, scan_cursor = %d, actor_user_id = %d, post_types = %s, error_message = %s WHERE id = %d';

    private const SQL_BOTH = 'UPDATE %i SET status = %s, finished_at = %s, heartbeat_at = %s, posts_scanned = %d, posts_passed = %d, posts_warned = %d, posts_failed = %d, posts_not_evaluated = %d, posts_total = %d, scan_cursor = %d, actor_user_id = %d, post_types = %s, error_message = %s WHERE id = %d';

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testBothNullableColumnsStaySqlNull(): void
    {
        $call = $this->save(null, null);

        $this->assertSame(self::SQL_BOTH_NULL, $call['sql']);
        $this->assertSame($this->args(), $call['args']);
    }

    public function testFinishedAtIsPlaceholderWhenPresent(): void
    {
        $call = $this->save('2026-01-02 00:00:00', null);

        $this->assertSame(self::SQL_FINISHED, $call['sql']);
        $this->assertSame($this->args('2026-01-02 00:00:00'), $call['args']);
        $this->assertStringNotContainsString('2026-01-02 00:00:00', $call['sql']);
    }

    public function testErrorMessageIsPlaceholderWhenPresent(): void
    {
        $call = $this->save(null, 'Audit batch failed.');

        $this->assertSame(self::SQL_ERROR, $call['sql']);
        $this->assertSame($this->args(null, 'Audit batch failed.'), $call['args']);
        $this->assertStringNotContainsString('Audit batch failed.', $call['sql']);
    }

    public function testBothNullableColumnsArePlaceholdersWhenPresent(): void
    {
        $call = $this->save('2026-01-02 00:00:00', 'stopped');

        $this->assertSame(self::SQL_BOTH, $call['sql']);
        $this->assertSame($this->args('2026-01-02 00:00:00', 'stopped'), $call['args']);
        $this->assertStringNotContainsString('stopped', $call['sql']);
        $this->assertStringNotContainsString('2026-01-02 00:00:00', $call['sql']);
    }

    public function testFailedUpdateStillThrows(): void
    {
        $this->installWpdb(false);
        $store = new WpAuditStore();

        $this->expectException(AuditException::class);
        $store->saveRun($this->auditRun(null, null));
    }

    public function testSaveRunDoesNotSpliceNullableSql(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/WordPress/WpAuditStore.php');

        $this->assertStringNotContainsString('$finishedSql', $src);
        $this->assertStringNotContainsString('$errorSql', $src);
        $this->assertStringNotContainsString("finished_at = ' .", $src);
        $this->assertStringNotContainsString("error_message = ' .", $src);
    }

    /**
     * @return array{sql: string, args: array<int, mixed>}
     */
    private function save(?string $finishedAt, ?string $errorMessage): array
    {
        $wpdb = $this->installWpdb(1);
        $store = new WpAuditStore();
        $saved = $store->saveRun($this->auditRun($finishedAt, $errorMessage));

        $this->assertSame(4, $saved->id);
        $this->assertCount(1, $wpdb->calls);

        return $wpdb->calls[0];
    }

    /**
     * @return array<int, mixed>
     */
    private function args(?string $finishedAt = null, ?string $errorMessage = null): array
    {
        $args = array(
            AuditSchema::runsTable(),
            'running',
        );
        if ($finishedAt !== null) {
            $args[] = $finishedAt;
        }

        array_push(
            $args,
            '2026-01-01 00:01:00',
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
            '["recipe","page"]'
        );

        if ($errorMessage !== null) {
            $args[] = $errorMessage;
        }

        $args[] = 4;

        return $args;
    }

    private function auditRun(?string $finishedAt, ?string $errorMessage): AuditRun
    {
        return new AuditRun(
            4,
            AuditRunStatus::Running,
            '2026-01-01 00:00:00',
            $finishedAt,
            '2026-01-01 00:01:00',
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
            array('recipe', 'page'),
            $errorMessage
        );
    }

    private function installWpdb(int|false $queryResult): object
    {
        $wpdb = new class($queryResult) {
            public string $prefix = 'wp_';

            /** @var list<array{sql: string, args: array<int, mixed>}> */
            public array $calls = array();

            public function __construct(private int|false $queryResult)
            {
            }

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

            public function query(string $sql): int|false
            {
                return $this->queryResult;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }
}
