<?php
/**
 * Scanner IN lists keep values in prepare arguments.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

use ContentLatch\Infrastructure\WordPress\WpAuditPostScanner;
use PHPUnit\Framework\TestCase;

final class WpAuditPostScannerQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testEmptyTypeOrStatusListDoesNotQuery(): void
    {
        $wpdb = $this->installWpdb();
        $scanner = new WpAuditPostScanner();

        $this->assertSame(array(), $scanner->scan(array(), array('publish'), 0, 10));
        $this->assertSame(array(), $scanner->scan(array('post'), array(), 0, 10));
        $this->assertSame(array(), $scanner->scan(array(''), array(''), 0, 10));
        $this->assertSame(0, $scanner->count(array('post'), array('')));
        $this->assertSame(array(), $wpdb->calls);
    }

    public function testOneTypeAndOneStatus(): void
    {
        $this->assertScan(
            array('recipe'),
            array('publish'),
            0,
            10,
            'SELECT ID, post_type FROM %i WHERE post_type IN (%s) AND post_status IN (%s) AND ID > %d ORDER BY ID ASC LIMIT %d',
            array('wp_posts', 'recipe', 'publish', 0, 10)
        );
    }

    public function testOneTypeAndMultipleStatuses(): void
    {
        $this->assertCountQuery(
            array('recipe'),
            array('publish', 'private'),
            'SELECT COUNT(ID) AS total FROM %i WHERE post_type IN (%s) AND post_status IN (%s,%s)',
            array('wp_posts', 'recipe', 'publish', 'private')
        );
    }

    public function testMultipleTypesAndOneStatus(): void
    {
        $this->assertScan(
            array('recipe', 'page'),
            array('publish'),
            20,
            5,
            'SELECT ID, post_type FROM %i WHERE post_type IN (%s,%s) AND post_status IN (%s) AND ID > %d ORDER BY ID ASC LIMIT %d',
            array('wp_posts', 'recipe', 'page', 'publish', 20, 5)
        );
    }

    public function testMultipleTypesAndMultipleStatuses(): void
    {
        $this->assertCountQuery(
            array('post', 'page'),
            array('publish', 'private'),
            'SELECT COUNT(ID) AS total FROM %i WHERE post_type IN (%s,%s) AND post_status IN (%s,%s)',
            array('wp_posts', 'post', 'page', 'publish', 'private')
        );
    }

    public function testManyPostTypesKeepMatchingPlaceholderCount(): void
    {
        $types = array();
        for ($i = 1; $i <= 25; $i++) {
            $types[] = 'type_' . $i;
        }

        $typeIn = implode(',', array_fill(0, 25, '%s'));
        $this->assertScan(
            $types,
            array('publish'),
            100,
            50,
            'SELECT ID, post_type FROM %i WHERE post_type IN (' . $typeIn . ') AND post_status IN (%s) AND ID > %d ORDER BY ID ASC LIMIT %d',
            array_merge(array('wp_posts'), $types, array('publish', 100, 50))
        );

        $this->assertCountQuery(
            $types,
            array('publish', 'private'),
            'SELECT COUNT(ID) AS total FROM %i WHERE post_type IN (' . $typeIn . ') AND post_status IN (%s,%s)',
            array_merge(array('wp_posts'), $types, array('publish', 'private'))
        );
    }

    public function testBlankAndUnsafeEntriesAreNormalizedBeforePlaceholders(): void
    {
        $this->assertScan(
            array('recipe', '', '!!!'),
            array('', 'private', 'DROP TABLE'),
            4,
            2,
            'SELECT ID, post_type FROM %i WHERE post_type IN (%s) AND post_status IN (%s,%s) AND ID > %d ORDER BY ID ASC LIMIT %d',
            array('wp_posts', 'recipe', 'private', 'droptable', 4, 2)
        );
    }

    public function testMaliciousLookingPostTypeRemainsABoundArgument(): void
    {
        $wpdb = $this->installWpdb();
        $scanner = new WpAuditPostScanner();
        $payload = "post'); DELETE FROM wp_posts; --";

        $scanner->scan(array($payload, 'recipe'), array('publish'), 0, 10);

        $sql  = $wpdb->calls[0]['sql'];
        $args = $wpdb->calls[0]['args'];

        $this->assertStringNotContainsString('DELETE', $sql);
        $this->assertStringNotContainsString('--', $sql);
        $this->assertStringNotContainsString($payload, $sql);
        $this->assertStringContainsString('post_type IN (%s,%s)', $sql);
        $this->assertStringNotContainsString("post_type IN ('", $sql);
        $this->assertSame(
            'SELECT ID, post_type FROM %i WHERE post_type IN (%s,%s) AND post_status IN (%s) AND ID > %d ORDER BY ID ASC LIMIT %d',
            $sql
        );
        // sanitize_key strips quotes/semicolons/spaces; result is still only a prepare arg.
        $this->assertSame('postdeletefromwp_posts--', $args[1]);
        $this->assertSame('recipe', $args[2]);
        $this->assertSame('publish', $args[3]);
    }

    public function testCursorPaginationArgumentOrder(): void
    {
        $this->assertScan(
            array('post', 'page', 'recipe'),
            array('publish', 'private'),
            42,
            15,
            'SELECT ID, post_type FROM %i WHERE post_type IN (%s,%s,%s) AND post_status IN (%s,%s) AND ID > %d ORDER BY ID ASC LIMIT %d',
            array('wp_posts', 'post', 'page', 'recipe', 'publish', 'private', 42, 15)
        );
    }

    public function testCountAndScanShareConsistentTypeAndStatusFilters(): void
    {
        $types    = array('recipe', 'page');
        $statuses = array('publish', 'private');

        $wpdb = $this->installWpdb(array(array('total' => '7')));
        $scanner = new WpAuditPostScanner();

        $this->assertSame(7, $scanner->count($types, $statuses));
        $scanner->scan($types, $statuses, 3, 8);

        $countSql = $wpdb->calls[0]['sql'];
        $scanSql  = $wpdb->calls[1]['sql'];

        $this->assertStringContainsString('post_type IN (%s,%s)', $countSql);
        $this->assertStringContainsString('post_status IN (%s,%s)', $countSql);
        $this->assertStringContainsString('post_type IN (%s,%s)', $scanSql);
        $this->assertStringContainsString('post_status IN (%s,%s)', $scanSql);

        $this->assertSame(
            array('wp_posts', 'recipe', 'page', 'publish', 'private'),
            $wpdb->calls[0]['args']
        );
        $this->assertSame(
            array('wp_posts', 'recipe', 'page', 'publish', 'private', 3, 8),
            $wpdb->calls[1]['args']
        );
    }

    public function testRuntimeValuesAreArgumentsNotSql(): void
    {
        $wpdb = $this->installWpdb(array(
            array('ID' => '11', 'post_type' => 'recipe'),
        ));
        $scanner = new WpAuditPostScanner();

        $posts = $scanner->scan(array('recipe'), array('publish', 'private'), 10, 25);

        $this->assertCount(1, $posts);
        $this->assertSame(11, $posts[0]->id);
        $this->assertSame('recipe', $posts[0]->postType);
        $this->assertStringNotContainsString('recipe', $wpdb->calls[0]['sql']);
        $this->assertStringNotContainsString('publish', $wpdb->calls[0]['sql']);
        $this->assertStringNotContainsString('private', $wpdb->calls[0]['sql']);
        $this->assertStringNotContainsString('wp_posts', $wpdb->calls[0]['sql']);
        $this->assertSame(
            array('wp_posts', 'recipe', 'publish', 'private', 10, 25),
            $wpdb->calls[0]['args']
        );
    }

    public function testScannerDoesNotInterpolateThePostsTableOrValues(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/WordPress/WpAuditPostScanner.php');

        $this->assertStringNotContainsString('{$wpdb->posts}', $src);
        $this->assertStringNotContainsString('{$typePlaceholders}', $src);
        $this->assertStringNotContainsString('{$statusPlaceholders}', $src);
        $this->assertStringNotContainsString('{$typeIn}', $src);
        $this->assertStringNotContainsString('{$statusIn}', $src);
        $this->assertStringContainsString('function stringPlaceholders', $src);
        $this->assertStringContainsString('sanitize_key', $src);
    }

    public function testStringPlaceholdersHelperEmitsOnlyPercentSTokens(): void
    {
        $method = new \ReflectionMethod(WpAuditPostScanner::class, 'stringPlaceholders');
        $method->setAccessible(true);
        $scanner = new WpAuditPostScanner();

        $this->assertSame('', $method->invoke($scanner, 0));
        $this->assertSame('%s', $method->invoke($scanner, 1));
        $this->assertSame('%s,%s,%s', $method->invoke($scanner, 3));
        $this->assertMatchesRegularExpression('/^%s(?:,%s)*$/', (string) $method->invoke($scanner, 40));
    }

    /**
     * @param string[] $types
     * @param string[] $statuses
     * @param array<int, mixed> $args
     */
    private function assertScan(array $types, array $statuses, int $cursor, int $limit, string $sql, array $args): void
    {
        $wpdb = $this->installWpdb();
        $scanner = new WpAuditPostScanner();

        $this->assertSame(array(), $scanner->scan($types, $statuses, $cursor, $limit));
        $this->assertSame($sql, $wpdb->calls[0]['sql']);
        $this->assertSame($args, $wpdb->calls[0]['args']);
    }

    /**
     * @param string[] $types
     * @param string[] $statuses
     * @param array<int, mixed> $args
     */
    private function assertCountQuery(array $types, array $statuses, string $sql, array $args): void
    {
        $wpdb = $this->installWpdb(array(array('total' => '4')));
        $scanner = new WpAuditPostScanner();

        $this->assertSame(4, $scanner->count($types, $statuses));
        $this->assertSame($sql, $wpdb->calls[0]['sql']);
        $this->assertSame($args, $wpdb->calls[0]['args']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function installWpdb(array $rows = array()): object
    {
        $wpdb = new class($rows) {
            public string $prefix = 'wp_';

            public string $posts = 'wp_posts';

            /** @var list<array{sql: string, args: array<int, mixed>}> */
            public array $calls = array();

            /**
             * @param array<int, array<string, mixed>> $rows
             */
            public function __construct(private array $rows)
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

            /**
             * @return array<int, array<string, mixed>>
             */
            public function get_results(string $sql, string $output = ''): array
            {
                return $this->rows;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }
}
