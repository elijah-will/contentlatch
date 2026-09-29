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

    public function testBlankEntriesAreRemovedBeforePlaceholders(): void
    {
        $this->assertScan(
            array('recipe', ''),
            array('', 'private'),
            4,
            2,
            'SELECT ID, post_type FROM %i WHERE post_type IN (%s) AND post_status IN (%s) AND ID > %d ORDER BY ID ASC LIMIT %d',
            array('wp_posts', 'recipe', 'private', 4, 2)
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
        $this->assertStringContainsString('function stringPlaceholders', $src);
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
