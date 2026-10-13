<?php
/**
 * CSV contract, bounded traversal, spreadsheet safety, and output failures.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application\Audit;

use ContentLatch\Application\Audit\AuditCsvExporter;
use ContentLatch\Application\Audit\AuditExportMetadata;
use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditRun;
use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Application\Audit\AuditStoreInterface;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Tests\Support\InMemoryAuditStore;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditCsvExporterTest extends TestCase
{
    private array $streams = array();

    public static function setUpBeforeClass(): void
    {
        stream_wrapper_register('clauditcsv', AuditCsvTestStream::class);
    }

    public static function tearDownAfterClass(): void
    {
        stream_wrapper_unregister('clauditcsv');
    }

    protected function setUp(): void
    {
        AuditCsvTestStream::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
    }

    public function testEmptyCompletedAuditWritesExactUtf8HeaderAndBom(): void
    {
        $stream = $this->stream();
        $metadata = $this->createMock(AuditExportMetadata::class);
        $metadata->expects($this->never())->method('post');
        $metadata->expects($this->never())->method('rule');

        $this->assertSame(0, (new AuditCsvExporter($this->store(), $metadata))->export(7, $stream));
        $this->assertSame(
            "\xEF\xBB\xBF\"Post ID\",\"Post Title\",\"Post Type\",\"Post Status\",\"Field Name\",\"Rule Name\",Severity,\"Validation Message\",\"Edit URL\"\r\n",
            $this->contents($stream)
        );
        $this->assertSame(array(AuditCsvExporter::HEADERS), $this->rows($stream));
        $this->assertTrue(is_resource($stream));
    }

    public function testSingleFindingUsesCurrentMetadataButPersistedTypeSeverityAndMessage(): void
    {
        $finding = $this->finding(3, 42);
        $stream = $this->stream();
        $metadata = $this->createMock(AuditExportMetadata::class);
        $metadata->expects($this->once())->method('post')->with(42)->willReturn(array(
            'title' => 'Current title', 'status' => 'draft', 'edit_url' => 'https://example.test/wp-admin/post.php?post=42&action=edit',
        ));
        $metadata->expects($this->once())->method('rule')->with(12)->willReturn(RuleFactory::rule(array(
            'id' => 12, 'name' => 'Renamed rule', 'severity' => RuleSeverity::Warning,
        )));

        $this->assertSame(1, (new AuditCsvExporter($this->store(array($finding)), $metadata))->export(7, $stream));
        $this->assertSame(array(
            '42', 'Current title', 'recipe', 'draft', 'Ingredients', 'Renamed rule', 'blocking',
            'Stored validation message', 'https://example.test/wp-admin/post.php?post=42&action=edit',
        ), $this->rows($stream)[1]);
        $this->assertStringContainsString("\r\n42,", $this->contents($stream));
    }

    public function testDeletedUntitledAndUneditablePostsAndDeletedRules(): void
    {
        $metadata = $this->createMock(AuditExportMetadata::class);
        $metadata->method('post')->willReturnCallback(static fn (int $id): ?array => match ($id) {
            1 => null,
            2 => array('title' => '', 'status' => 'private', 'edit_url' => ''),
            default => array('title' => 'No edit permission', 'status' => 'publish', 'edit_url' => ''),
        });
        $metadata->method('rule')->willReturn(null);
        $stream = $this->stream();

        $this->assertSame(3, (new AuditCsvExporter($this->store(array(
            $this->finding(1, 1), $this->finding(4, 2, RuleSeverity::Warning), $this->finding(9, 3),
        )), $metadata))->export(7, $stream));
        $rows = $this->rows($stream);
        $this->assertSame(array('1', 'Content no longer available', 'recipe', '', 'field_ingredients', 'Deleted rule (#12)', 'blocking', 'Stored validation message', ''), $rows[1]);
        $this->assertSame('(no title)', $rows[2][1]);
        $this->assertSame('private', $rows[2][3]);
        $this->assertSame('warning', $rows[2][6]);
        $this->assertSame('', $rows[2][8]);
        $this->assertSame('', $rows[3][8]);
    }

    #[DataProvider('batchSizes')]
    public function testBatchesPinRunAndTraverseGapsWithoutDuplicatesOrSkips(int $total): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())->method('findRun')->with(7)->willReturn($this->completedRun());
        $calls = array();
        $store->method('findFindingsAfterId')->willReturnCallback(function (int $runId, int $after, int $limit) use ($total, &$calls): array {
            $this->assertSame(7, $runId);
            $this->assertSame(500, $limit);
            $calls[] = $after;
            $batch = array();
            for ($i = intdiv($after, 3) + 1; $i <= min($total, intdiv($after, 3) + $limit); $i++) {
                $batch[] = $this->finding($i * 3, $i, $i % 2 ? RuleSeverity::Fail : RuleSeverity::Warning);
            }
            return $batch;
        });
        $stream = $this->stream();

        $this->assertSame($total, (new AuditCsvExporter($store, $this->metadata()))->export(7, $stream));
        $rows = $this->rows($stream);
        $this->assertCount($total + 1, $rows);
        $this->assertSame(array_map('strval', range(1, $total)), array_column(array_slice($rows, 1), 0));
        $this->assertCount((int) ceil($total / 500) + 1, $calls);
        $this->assertSame($total * 3, $calls[array_key_last($calls)]);
    }

    public static function batchSizes(): array
    {
        return array('exact boundary' => array(500), 'partial second batch' => array(503), 'two full batches' => array(1000));
    }

    public function testOnlySelectedRunIsExportedEvenWhenAnotherRunIsNewer(): void
    {
        $other = new AuditFinding(2, 8, 99, 'post', 12, 'field_ingredients', 'v1', 'required', RuleSeverity::Fail, 'Other run', '2026-10-13 12:00:00');
        $store = $this->store(array($this->finding(3, 43), $other, $this->finding(1, 42)));
        $store->nextRunId = 8;
        $store->insertRun(1, array('post'), 1, '2026-10-13 12:00:00');
        $stream = $this->stream();

        $this->assertSame(2, (new AuditCsvExporter($store, $this->metadata()))->export(7, $stream));
        $this->assertSame(array('42', '43'), array_column(array_slice($this->rows($stream), 1), 0));
        $this->assertStringNotContainsString('Other run', $this->contents($stream));
    }

    #[DataProvider('invalidBatches')]
    public function testInvalidBatchCannotSilentlyDuplicateOrExportAnotherRun(string $case): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->method('findRun')->willReturn($this->completedRun());
        $batch = match ($case) {
            'duplicate' => array($this->finding(1, 42), $this->finding(1, 42)),
            'descending' => array($this->finding(2, 42), $this->finding(1, 43)),
            'other run' => array(new AuditFinding(1, 8, 42, 'post', 12, 'field', 'v1', 'required', RuleSeverity::Fail, 'Message', '2026-10-12 12:00:00')),
            'oversized' => array_map(fn (int $id): AuditFinding => $this->finding($id, $id), range(1, 501)),
        };
        $store->expects($this->once())->method('findFindingsAfterId')->willReturn($batch);
        $this->expectException(AuditException::class);
        (new AuditCsvExporter($store, $this->metadata()))->export(7, $this->stream());
    }

    public static function invalidBatches(): array
    {
        return array(array('duplicate'), array('descending'), array('other run'), array('oversized'));
    }

    public function testMetadataAndMissingRulesAreCachedOnlyWithinEachBatch(): void
    {
        $findings = array();
        for ($id = 1; $id <= 1001; $id++) {
            $findings[] = $this->finding($id, 42);
        }
        $metadata = $this->createMock(AuditExportMetadata::class);
        $metadata->expects($this->exactly(3))->method('post')->with(42)->willReturn(null);
        $metadata->expects($this->exactly(3))->method('rule')->with(12)->willReturn(null);

        $this->assertSame(1001, (new AuditCsvExporter($this->store($findings), $metadata))->export(7, $this->stream()));
    }

    #[DataProvider('textCases')]
    public function testCsvTextRoundTripsAndNeutralizesRiskyPrefixes(string $text, bool $guard): void
    {
        $metadata = $this->createMock(AuditExportMetadata::class);
        $metadata->method('post')->willReturn(array('title' => $text, 'status' => $text, 'edit_url' => $text));
        $metadata->method('rule')->willReturn(RuleFactory::rule(array(
            'name' => $text,
            'validations' => array(RuleFactory::validation(array('field' => RuleFactory::field('field_ingredients', 'ingredients', $text)))),
        )));
        $finding = $this->finding(1, 42, RuleSeverity::Fail, $text, $text);
        $stream = $this->stream();
        (new AuditCsvExporter($this->store(array($finding)), $metadata))->export(7, $stream);
        $row = $this->rows($stream)[1];
        $expected = $guard ? "'" . $text : $text;
        foreach (array(1, 2, 3, 4, 5, 7, 8) as $column) {
            $this->assertSame($expected, $row[$column], 'Column ' . $column);
        }
        $this->assertSame('42', $row[0]);
        $this->assertSame('blocking', $row[6]);
        $this->assertStringEndsWith("\r\n", $this->contents($stream));
    }

    public static function textCases(): array
    {
        $safe = array('plain text', '  ordinary spaces', 'A "quote", comma', "Line one\r\nLine two\nLine three", '東京 café 🥣', 'C:\\folder\\"quoted"', 'A,=1+1', 'A;@SUM(1)', "An embedded\ttab", "'=SUM(1,2)");
        $unsafe = array('=SUM(1,2)', '+1+2', '-1+2', '@SUM(1)', '   =1', "\t=1", "\r\n+2", "\x00@x", "\x0B-1", " \tordinary", "\tordinary", "\nordinary", "\u{00A0}=1", "\u{200B} =1", "\u{FEFF}=1", "\u{034F}=1", '＝1', '＋1', '－1', '＠SUM(1)', '　＝1', '﹦1', '﹢1', '﹣1', '﹫x', '⁼1', '₌1', '⁺1', '₊1', '⁻1', '₋1', '−1', '﬩1');
        return array_merge(
            array_map(static fn (string $text): array => array($text, false), $safe),
            array_map(static fn (string $text): array => array($text, true), $unsafe)
        );
    }

    public function testEmptyAndFullRepeaterMessagesArePreserved(): void
    {
        $message = str_repeat('Long validation message, "quoted". ', 200) . "\nAffected rows: 1.2, 4.3.";
        $stream = $this->stream();
        (new AuditCsvExporter($this->store(array($this->finding(1, 1, message: ''), $this->finding(2, 2, message: $message))), $this->metadata()))->export(7, $stream);
        $this->assertSame('', $this->rows($stream)[1][7]);
        $this->assertSame($message, $this->rows($stream)[2][7]);
    }

    public function testLaterDatabaseFailurePropagatesWithoutSuccessOrHtml(): void
    {
        $failure = new AuditException('Database batch failed.');
        $store = $this->createMock(AuditStoreInterface::class);
        $store->method('findRun')->willReturn($this->completedRun());
        $store->expects($this->exactly(2))->method('findFindingsAfterId')->willReturnCallback(function (int $runId, int $after) use ($failure): array {
            if ($after !== 0) {
                throw $failure;
            }
            return array_map(fn (int $id): AuditFinding => $this->finding($id, $id), range(1, 500));
        });
        $stream = $this->stream();
        try {
            (new AuditCsvExporter($store, $this->metadata()))->export(7, $stream);
            $this->fail('Later batch failure must propagate.');
        } catch (AuditException $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertCount(501, $this->rows($stream));
        $this->assertStringNotContainsString('Database batch failed', $this->contents($stream));
        $this->assertStringNotContainsString('<', $this->contents($stream));
    }

    public function testMetadataFailurePropagatesWithoutAppendingErrorText(): void
    {
        $failure = new AuditException('Metadata lookup failed.');
        $metadata = $this->createMock(AuditExportMetadata::class);
        $metadata->method('post')->willThrowException($failure);
        $stream = $this->stream();
        try {
            (new AuditCsvExporter($this->store(array($this->finding(1, 42))), $metadata))->export(7, $stream);
            $this->fail('Metadata failures must propagate.');
        } catch (AuditException $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertSame(array(AuditCsvExporter::HEADERS), $this->rows($stream));
        $this->assertStringNotContainsString('Metadata lookup failed', $this->contents($stream));
    }

    #[DataProvider('invalidRuns')]
    public function testIncompleteOrMissingRunWritesNothing(?AuditRunStatus $status): void
    {
        $store = $this->store();
        $store->runs = $status === null ? array() : array(7 => $this->completedRun()->withStatus($status));
        $stream = $this->stream();
        try {
            (new AuditCsvExporter($store, $this->metadata()))->export(7, $stream);
            $this->fail('Only completed runs may be exported.');
        } catch (AuditException $error) {
            $this->assertSame('Only completed audits can be exported.', $error->getMessage());
        }
        $this->assertSame('', $this->contents($stream));
    }

    public static function invalidRuns(): array
    {
        return array(array(null), array(AuditRunStatus::Pending), array(AuditRunStatus::Running), array(AuditRunStatus::Failed), array(AuditRunStatus::Cancelled));
    }

    public function testInvalidUtf8IsRejectedRatherThanSilentlyChanged(): void
    {
        $this->expectException(AuditException::class);
        $this->expectExceptionMessage('valid UTF-8');
        (new AuditCsvExporter($this->store(array($this->finding(1, 1, message: "bad\xFFtext"))), $this->metadata()))->export(7, $this->stream());
    }

    public function testReadOnlyAndInvalidStreamsAreRejected(): void
    {
        $exporter = new AuditCsvExporter($this->store(), $this->metadata());
        $closed = fopen('php://memory', 'w+b');
        fclose($closed);
        foreach (array(null, 'php://output', $closed, $this->stream(__FILE__, 'rb')) as $stream) {
            try {
                $exporter->export(7, $stream);
                $this->fail('Expected invalid stream failure.');
            } catch (AuditException $error) {
                $this->assertStringContainsString('writable stream', $error->getMessage());
            }
        }
    }

    #[DataProvider('writeFailures')]
    public function testStreamFailuresNeverReturnSuccess(int $failAfter, bool $returnFalse, bool $flushFails): void
    {
        AuditCsvTestStream::$failAfter = $failAfter;
        AuditCsvTestStream::$returnFalse = $returnFalse;
        AuditCsvTestStream::$flushFails = $flushFails;
        $stream = $this->stream('clauditcsv://output', 'wb');
        $this->expectException(AuditException::class);
        (new AuditCsvExporter($this->store(array($this->finding(1, 1))), $this->metadata()))->export(7, $stream);
    }

    public static function writeFailures(): array
    {
        return array('BOM failure' => array(0, true, false), 'zero write' => array(1, false, false), 'partial row failure' => array(160, false, false), 'flush failure' => array(-1, false, true));
    }

    public function testShortWritesAreCompletedWithoutDuplicatingBytes(): void
    {
        AuditCsvTestStream::$chunk = 2;
        $exporter = new AuditCsvExporter($this->store(array($this->finding(1, 1))), $this->metadata());
        $expected = $this->stream();
        $exporter->export(7, $expected);
        $this->assertSame(1, $exporter->export(7, $this->stream('clauditcsv://output', 'wb')));
        $this->assertSame($this->contents($expected), AuditCsvTestStream::$bytes);
    }

    public function testLargeExportReleasesMetadataBetweenBatches(): void
    {
        $total = 5001;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->method('findRun')->willReturn($this->completedRun());
        $store->method('findFindingsAfterId')->willReturnCallback(function (int $runId, int $after, int $limit) use ($total): array {
            $batch = array();
            for ($id = $after + 1; $id <= min($total, $after + $limit); $id++) {
                $batch[] = $this->finding($id, $id);
            }
            return $batch;
        });
        $metadata = new class implements AuditExportMetadata {
            public int $firstBatchMemory = 0;
            public int $maximumMemory = 0;
            public function post(int $postId): ?array
            {
                $memory = memory_get_usage();
                if ($postId === 500) {
                    $this->firstBatchMemory = $memory;
                }
                $this->maximumMemory = max($this->maximumMemory, $memory);
                return array('title' => str_repeat('T', 4096), 'status' => 'publish', 'edit_url' => '');
            }
            public function rule(int|string $ruleId): ?Rule { return null; }
        };
        AuditCsvTestStream::$discard = true;
        $this->assertSame($total, (new AuditCsvExporter($store, $metadata))->export(7, $this->stream('clauditcsv://output', 'wb')));
        $this->assertGreaterThan(20_000_000, AuditCsvTestStream::$written);
        $this->assertLessThan(2 * 1024 * 1024, $metadata->maximumMemory - $metadata->firstBatchMemory);
    }

    private function completedRun(): AuditRun
    {
        return new AuditRun(7, AuditRunStatus::Complete, '2026-10-12 12:00:00', '2026-10-12 12:01:00', '2026-10-12 12:01:00', 1, 0, 0, 1, 0, 1, 42, 1, array('recipe'));
    }

    private function store(array $findings = array()): InMemoryAuditStore
    {
        $store = new InMemoryAuditStore();
        $store->runs[7] = $this->completedRun();
        $store->findings = $findings;
        return $store;
    }

    private function finding(int $id, int $postId, RuleSeverity $severity = RuleSeverity::Fail, string $message = 'Stored validation message', string $type = 'recipe'): AuditFinding
    {
        return new AuditFinding($id, 7, $postId, $type, 12, 'field_ingredients', 'v1', 'required', $severity, $message, '2026-10-12 12:00:00');
    }

    private function metadata(): AuditExportMetadata
    {
        $metadata = $this->createStub(AuditExportMetadata::class);
        $metadata->method('post')->willReturn(array('title' => 'Title', 'status' => 'publish', 'edit_url' => ''));
        $metadata->method('rule')->willReturn(RuleFactory::rule(array('id' => 12)));
        return $metadata;
    }

    private function stream(string $path = 'php://memory', string $mode = 'w+b'): mixed
    {
        $stream = fopen($path, $mode);
        $this->streams[] = $stream;
        return $stream;
    }

    private function contents(mixed $stream): string
    {
        rewind($stream);
        return stream_get_contents($stream);
    }

    private function rows(mixed $stream): array
    {
        rewind($stream);
        $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));
        $rows = array();
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        return $rows;
    }
}

/** Test-only stream: configurable short writes/failures or constant-memory sink. */
final class AuditCsvTestStream
{
    public mixed $context;
    public static string $bytes = '';
    public static int $written = 0;
    public static int $failAfter = -1;
    public static bool $returnFalse = false;
    public static bool $flushFails = false;
    public static bool $discard = false;
    public static int $chunk = PHP_INT_MAX;

    public static function reset(): void
    {
        self::$bytes = '';
        self::$written = 0;
        self::$failAfter = -1;
        self::$returnFalse = self::$flushFails = self::$discard = false;
        self::$chunk = PHP_INT_MAX;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_stat(): array { return array(); }
    public function stream_eof(): bool { return false; }
    public function stream_flush(): bool { return !self::$flushFails; }
    public function stream_write(string $data): int|false
    {
        if (self::$failAfter >= 0 && self::$written >= self::$failAfter) {
            return self::$returnFalse ? false : 0;
        }
        $length = min(strlen($data), self::$chunk);
        if (self::$failAfter >= 0) {
            $length = min($length, self::$failAfter - self::$written);
        }
        if (!self::$discard) {
            self::$bytes .= substr($data, 0, $length);
        }
        self::$written += $length;
        return $length;
    }
}
