<?php
/**
 * Allowlisted finding SQL for WpAuditStore.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

use ContentLatch\Application\Audit\AuditFindingQuery;
use ContentLatch\Infrastructure\WordPress\AuditSchema;
use ContentLatch\Infrastructure\WordPress\WpAuditStore;
use PHPUnit\Framework\TestCase;

final class WpAuditStoreFindingQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testRunOnlyUsesPlaceholdersWithoutOptionalFilters(): void
    {
        $this->assertLookup(
            new AuditFindingQuery(7),
            'run_id = %d',
            array(7)
        );
    }

    public function testRunAndSeverity(): void
    {
        $this->assertLookup(
            new AuditFindingQuery(7, 'fail'),
            'run_id = %d AND severity = %s',
            array(7, 'fail')
        );
    }

    public function testRunAndRule(): void
    {
        $this->assertLookup(
            new AuditFindingQuery(7, null, 12),
            'run_id = %d AND rule_id = %s',
            array(7, '12')
        );
    }

    public function testRunAndPostType(): void
    {
        $this->assertLookup(
            new AuditFindingQuery(7, null, null, 'recipe'),
            'run_id = %d AND post_type = %s',
            array(7, 'recipe')
        );
    }

    public function testRunSeverityAndRule(): void
    {
        $this->assertLookup(
            new AuditFindingQuery(7, 'warning', '42'),
            'run_id = %d AND severity = %s AND rule_id = %s',
            array(7, 'warning', '42')
        );
    }

    public function testRunSeverityAndPostType(): void
    {
        $this->assertLookup(
            new AuditFindingQuery(7, 'fail', null, 'post'),
            'run_id = %d AND severity = %s AND post_type = %s',
            array(7, 'fail', 'post')
        );
    }

    public function testRunRuleAndPostType(): void
    {
        $this->assertLookup(
            new AuditFindingQuery(7, null, 3, 'page'),
            'run_id = %d AND rule_id = %s AND post_type = %s',
            array(7, '3', 'page')
        );
    }

    public function testAllOptionalFiltersTogether(): void
    {
        $this->assertLookup(
            new AuditFindingQuery(9, 'fail', 4, 'recipe'),
            'run_id = %d AND severity = %s AND rule_id = %s AND post_type = %s',
            array(9, 'fail', '4', 'recipe')
        );
    }

    public function testQueryFindingsPassesLimitAndOffsetAfterFilters(): void
    {
        $wpdb = $this->installWpdb();
        $store = new WpAuditStore();

        $store->queryFindings(new AuditFindingQuery(7, 'fail', 4, 'recipe', 25, 50));

        $call = $wpdb->calls[0];
        $this->assertSame(
            'SELECT * FROM %i WHERE run_id = %d AND severity = %s AND rule_id = %s AND post_type = %s ORDER BY id ASC LIMIT %d OFFSET %d',
            $call['sql']
        );
        $this->assertSame(
            array(AuditSchema::findingsTable(), 7, 'fail', '4', 'recipe', 25, 50),
            $call['args']
        );
        $this->assertStringNotContainsString('fail', $call['sql']);
        $this->assertStringNotContainsString('recipe', $call['sql']);
    }

    public function testFindingLookupsDoNotConcatenateAWhereFragment(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/WordPress/WpAuditStore.php');

        $this->assertStringNotContainsString('function findingWhere', $src);
        $this->assertStringNotContainsString('$where', $src);
        $this->assertStringNotContainsString("WHERE ' .", $src);
        $this->assertStringNotContainsString('findingWhere()', $src);
    }

    /**
     * @param array<int, int|string> $filterArgs
     */
    private function assertLookup(AuditFindingQuery $query, string $where, array $filterArgs): void
    {
        $wpdb = $this->installWpdb();
        $store = new WpAuditStore();
        $table = AuditSchema::findingsTable();

        $store->queryFindings($query);
        $store->countFindings($query);
        $store->countDistinctPosts($query);

        $rows = $wpdb->calls[0];
        $count = $wpdb->calls[1];
        $posts = $wpdb->calls[2];

        $this->assertSame(
            'SELECT * FROM %i WHERE ' . $where . ' ORDER BY id ASC LIMIT %d OFFSET %d',
            $rows['sql']
        );
        $this->assertSame(
            'SELECT COUNT(*) FROM %i WHERE ' . $where,
            $count['sql']
        );
        $this->assertSame(
            'SELECT COUNT(DISTINCT post_id) FROM %i WHERE ' . $where,
            $posts['sql']
        );

        $expected = array_merge(array($table), $filterArgs);
        $this->assertSame(array_merge($expected, array($query->limit, $query->offset)), $rows['args']);
        $this->assertSame($expected, $count['args']);
        $this->assertSame($expected, $posts['args']);

        foreach (array($rows, $count, $posts) as $call) {
            $this->assertStringNotContainsString('$where', $call['sql']);
            $this->assertDoesNotMatchRegularExpression('/\b(fail|warning|recipe|page|post)\b/', $call['sql']);
        }
    }

    private function installWpdb(): object
    {
        $wpdb = new class {
            public string $prefix = 'wp_';

            /** @var list<array{sql: string, args: array<int, mixed>}> */
            public array $calls = array();

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

            public function get_results(string $sql, string $output = ''): array
            {
                return array();
            }

            public function get_var(string $sql): string
            {
                return '0';
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }
}
