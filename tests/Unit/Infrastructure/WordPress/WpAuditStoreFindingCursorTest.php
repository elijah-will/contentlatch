<?php
/**
 * Bounded cursor queries and explicit read failures.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditStoreInterface;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Infrastructure\WordPress\WpAuditStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WpAuditStoreFindingCursorTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testCursorQueriesArePreparedBoundedAndPinnedToTheRun(): void
    {
        $wpdb = $this->installWpdb();
        $store = new WpAuditStore();
        $wpdb->rows = array($this->row(2, 'fail'), $this->row(9, 'warning'));

        $first = $store->findFindingsAfterId(7, 0, 2);
        $this->assertSame(array(2, 9), $this->ids($first));
        $this->assertSame(RuleSeverity::Fail, $first[0]->severity);
        $this->assertSame(RuleSeverity::Warning, $first[1]->severity);
        $this->assertSame(7, $first[0]->runId);
        $this->assertSame('Missing title', $first[0]->message);
        $this->assertSame('core:post_title', $first[0]->fieldKey);
        $this->assertSame(42, $first[0]->postId);
        $this->assertSame('post', $first[0]->postType);
        $this->assertSame('12', $first[0]->ruleId);
        $this->assertSame('required-title', $first[0]->validationId);
        $this->assertSame('required', $first[0]->code);
        $this->assertSame('2026-10-12 12:00:00', $first[0]->createdAt);
        $this->assertSame(array(), $first[0]->context);

        $wpdb->rows = array($this->row(15, 'warning'), $this->row(24, 'fail'));
        $second = $store->findFindingsAfterId(7, $first[1]->id, 2);
        $this->assertSame(array(15, 24), $this->ids($second));

        $wpdb->rows = array();
        $this->assertSame(array(), $store->findFindingsAfterId(7, $second[1]->id, 2));
        $this->assertSame(array(), $store->findFindingsAfterId(8, 0, 2));
        $this->assertSame(array(2, 9, 15, 24), $this->ids(array_merge($first, $second)));

        $expectedArgs = array(
            array('custom_contentlatch_audit_findings', 7, 0, 2),
            array('custom_contentlatch_audit_findings', 7, 9, 2),
            array('custom_contentlatch_audit_findings', 7, 24, 2),
            array('custom_contentlatch_audit_findings', 8, 0, 2),
        );
        $this->assertCount(4, $wpdb->calls);
        foreach ($wpdb->calls as $index => $call) {
            $this->assertSame(
                'SELECT * FROM %i WHERE run_id = %d AND id > %d ORDER BY id ASC LIMIT %d',
                $call['sql']
            );
            $this->assertSame($expectedArgs[$index], $call['args']);
        }
        $this->assertSame(array_column($wpdb->calls, 'prepared'), $wpdb->executed);
    }

    #[DataProvider('limits')]
    public function testLimitsAreAlwaysPositiveAndCapped(int $input, int $expected): void
    {
        $wpdb = $this->installWpdb();

        (new WpAuditStore())->findFindingsAfterId(7, 0, $input);

        $this->assertSame($expected, $wpdb->calls[0]['args'][3]);
        $this->assertSame(array($wpdb->calls[0]['prepared']), $wpdb->executed);
    }

    public static function limits(): array
    {
        return array(
            'minimum integer' => array(PHP_INT_MIN, 1),
            'negative'        => array(-10, 1),
            'zero'            => array(0, 1),
            'one'             => array(1, 1),
            'maximum'         => array(AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE, AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE),
            'above maximum'   => array(AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE + 1, AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE),
            'maximum integer' => array(PHP_INT_MAX, AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE),
        );
    }

    public function testNegativeCursorStartsAtZero(): void
    {
        $wpdb = $this->installWpdb();

        (new WpAuditStore())->findFindingsAfterId(7, -10, 2);

        $this->assertSame(0, $wpdb->calls[0]['args'][2]);
    }

    public function testSuccessfulZeroRowQueryReturnsEmptyArray(): void
    {
        $wpdb = $this->installWpdb();

        $this->assertSame(array(), (new WpAuditStore())->findFindingsAfterId(7, 0, 2));
        $this->assertSame('', $wpdb->last_error);
        $this->assertSame(array($wpdb->calls[0]['prepared']), $wpdb->executed);
    }

    #[DataProvider('failedResults')]
    public function testDatabaseFailureNeverLooksLikeEndOfResults(string $error, bool $staleRows): void
    {
        $wpdb = $this->installWpdb();
        $wpdb->failQuery = true;
        $wpdb->queryError = $error;
        if ($staleRows) {
            // A not-ready connection can fail before wpdb clears old results.
            $wpdb->last_result = array((object) $this->row(1, 'fail'));
        }

        try {
            (new WpAuditStore())->findFindingsAfterId(7, 0, 2);
            $this->fail('Failed execution must throw rather than return findings.');
        } catch (AuditException $exception) {
            $this->assertSame('Could not load audit findings.', $exception->getMessage());
        }
        $this->assertSame($error, $wpdb->last_error);
        $this->assertSame(array($wpdb->calls[0]['prepared']), $wpdb->executed);
    }

    public static function failedResults(): array
    {
        return array(
            'empty results without error' => array('', false),
            'empty results with error'    => array('Database connection lost', false),
            'stale results without error' => array('', true),
        );
    }

    /** @return array<string, int|string> */
    private function row(int $id, string $severity): array
    {
        return array(
            'id'            => $id,
            'run_id'        => 7,
            'post_id'       => 42,
            'post_type'     => 'post',
            'rule_id'       => '12',
            'field_key'     => 'core:post_title',
            'validation_id' => 'required-title',
            'code'          => 'required',
            'severity'      => $severity,
            'message'       => 'Missing title',
            'created_at'    => '2026-10-12 12:00:00',
        );
    }

    /** @param AuditFinding[] $findings */
    private function ids(array $findings): array
    {
        return array_map(static fn (AuditFinding $finding): int => $finding->id, $findings);
    }

    private function installWpdb(): object
    {
        $wpdb = new class {
            public string $prefix = 'custom_';
            public string $last_error = '';
            public string $queryError = '';
            public bool $failQuery = false;
            public array $rows = array();
            public array $last_result = array();
            public array $calls = array();
            public array $executed = array();

            public function prepare(string $sql, mixed ...$args): string
            {
                // Distinct output proves execution uses the prepared statement.
                $prepared = 'prepared-query-' . count($this->calls);
                $this->calls[] = array('sql' => $sql, 'args' => $args, 'prepared' => $prepared);

                return $prepared;
            }

            public function query(string $sql): int|false
            {
                $this->executed[] = $sql;
                $this->last_error = $this->queryError;
                if ($this->failQuery) {
                    return false;
                }

                $this->last_result = array_map(static fn (array $row): object => (object) $row, $this->rows);

                return count($this->last_result);
            }

            public function get_results(string $sql, string $output): array
            {
                TestCase::assertSame(ARRAY_A, $output);
                // Match wpdb: get_results discards query()'s false return value.
                $this->query($sql);

                return array_map('get_object_vars', $this->last_result);
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }
}
