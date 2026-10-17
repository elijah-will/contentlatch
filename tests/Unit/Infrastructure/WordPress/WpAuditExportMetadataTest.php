<?php
/**
 * Current metadata, Core edit-link delegation, and bounded cache lifetime.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Infrastructure\WordPress\WpAuditExportMetadata;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WpAuditExportMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        // Namespace doubles must not shadow Core functions in other tests.
        require_once dirname(__DIR__, 3) . '/Support/wordpress-audit-export-functions.php';
        $GLOBALS['wpdb'] = (object) array('ready' => true, 'last_error' => '', 'num_queries' => 0);
        $GLOBALS['contentlatch_test_export'] = array(
            'title' => 'Current "title", café',
            'status' => 'private',
            'edit_url' => 'https://example.test/wp-admin/post.php?post=42&action=edit',
            'missing' => false,
            'throw_on_edit' => false,
            'post_calls' => array(),
            'edit_calls' => array(),
            'deleted' => array(),
            'cache' => array('posts' => array(), 'post_meta' => array()),
        );
    }

    public function testCurrentMetadataUsesRawCoreEditLinkAndReleasesTouchedCacheKeys(): void
    {
        // Unrelated cached posts must not be flushed.
        $GLOBALS['contentlatch_test_export']['cache']['posts'][99] = (object) array('ID' => 99);
        $metadata = $this->metadata()->post(42);

        $this->assertSame(array(
            'title' => 'Current "title", café',
            'status' => 'private',
            'edit_url' => 'https://example.test/wp-admin/post.php?post=42&action=edit',
        ), $metadata);
        $state = $GLOBALS['contentlatch_test_export'];
        $this->assertSame(array(42), $state['post_calls']);
        $this->assertSame(array(array(42, 'raw')), $state['edit_calls']);
        $this->assertTrue($state['cached_during_edit']);
        $this->assertSame(array(array(42, 'posts'), array(42, 'post_meta')), $state['deleted']);
        $this->assertSame(array(99), array_keys($state['cache']['posts']));
        $this->assertSame(array(), $state['cache']['post_meta']);
    }

    public function testMissingPostIsDistinctFromUntitledPost(): void
    {
        $metadata = $this->metadata();
        $GLOBALS['contentlatch_test_export']['missing'] = true;
        $this->assertNull($metadata->post(42));
        $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['edit_calls']);

        $GLOBALS['contentlatch_test_export']['missing'] = false;
        $GLOBALS['contentlatch_test_export']['title'] = '';
        $this->assertSame('', $metadata->post(43)['title']);
    }

    public function testUnavailableEditLinkRemainsEmpty(): void
    {
        // Core returns null when edit_post is denied or the type has no link.
        $GLOBALS['contentlatch_test_export']['edit_url'] = null;
        $this->assertSame('', $this->metadata()->post(42)['edit_url']);
        $this->assertSame(array(array(42, 'raw')), $GLOBALS['contentlatch_test_export']['edit_calls']);
    }

    public function testRuleLookupUsesCurrentRepositoryAndEvictsItsPostAndMeta(): void
    {
        $rule = RuleFactory::rule(array('id' => 12, 'name' => 'Renamed current rule'));
        $repository = $this->createMock(RuleRepositoryInterface::class);
        $repository->expects($this->once())->method('find')->with('12')->willReturn($rule);
        $GLOBALS['contentlatch_test_export']['cache']['posts'][12] = (object) array('ID' => 12);
        $GLOBALS['contentlatch_test_export']['cache']['post_meta'][12] = array('rule' => 'document');

        $this->assertSame($rule, (new WpAuditExportMetadata($repository))->rule('12'));
        $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['cache']['posts']);
        $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['cache']['post_meta']);
        $this->assertSame(array(array(12, 'posts'), array(12, 'post_meta')), $GLOBALS['contentlatch_test_export']['deleted']);
    }

    public function testMissingRuleRemainsNull(): void
    {
        $repository = $this->createMock(RuleRepositoryInterface::class);
        $repository->expects($this->once())->method('find')->with(12)->willReturn(null);
        $this->assertNull((new WpAuditExportMetadata($repository))->rule(12));
    }

    public function testInvalidIdsCannotResolveTheGlobalPost(): void
    {
        $repository = $this->createMock(RuleRepositoryInterface::class);
        $repository->expects($this->never())->method('find');
        $metadata = new WpAuditExportMetadata($repository);
        foreach (array(0, -1, '', 'legacy-rule', '1e3') as $id) {
            $this->assertNull($metadata->rule($id));
        }
        $this->assertNull($metadata->post(0));
        $this->assertNull($metadata->post(-1));
        $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['post_calls']);
    }

    public function testDatabaseNotReadyIsNotADeletedPost(): void
    {
        $GLOBALS['wpdb']->ready = false;
        $GLOBALS['wpdb']->last_error = '';
        $this->expectException(AuditException::class);
        $this->expectExceptionMessage('Could not load audit export metadata.');
        $this->metadata()->post(42);
    }

    public function testStaleLastErrorDoesNotFailASuccessfulPostLookup(): void
    {
        $GLOBALS['wpdb']->last_error = 'Earlier unrelated query failed';
        $post = $this->metadata()->post(42);

        $this->assertSame('Current "title", café', $post['title']);
        $this->assertSame('private', $post['status']);
        $this->assertSame('', $GLOBALS['wpdb']->last_error);
        $this->assertSame(1, $GLOBALS['wpdb']->num_queries);
    }

    public function testStaleLastErrorIsKeptWhenThePostIsAlreadyCached(): void
    {
        $cached = (object) array(
            'ID' => 42,
            'post_title' => 'Cached title',
            'post_status' => 'publish',
        );
        $GLOBALS['contentlatch_test_export']['cache']['posts'][42] = $cached;
        $GLOBALS['wpdb']->last_error = 'Earlier unrelated query failed';

        $post = $this->metadata()->post(42);

        $this->assertSame('Cached title', $post['title']);
        $this->assertSame('publish', $post['status']);
        $this->assertSame('Earlier unrelated query failed', $GLOBALS['wpdb']->last_error);
        $this->assertSame(0, $GLOBALS['wpdb']->num_queries);
    }

    public function testStaleLastErrorDoesNotFailASuccessfulRuleLookup(): void
    {
        $rule = RuleFactory::rule(array('id' => 12, 'name' => 'Renamed current rule'));
        $repository = $this->createMock(RuleRepositoryInterface::class);
        $repository->expects($this->once())->method('find')->with('12')->willReturn($rule);
        $GLOBALS['wpdb']->last_error = 'Earlier unrelated query failed';

        $this->assertSame($rule, (new WpAuditExportMetadata($repository))->rule('12'));
        $this->assertSame('Earlier unrelated query failed', $GLOBALS['wpdb']->last_error);
        $this->assertSame(0, $GLOBALS['wpdb']->num_queries);
    }

    public function testRuleQueryFailureIsNotAMissingRule(): void
    {
        $repository = $this->createMock(RuleRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(static function (): null {
            $GLOBALS['wpdb']->num_queries++;
            $GLOBALS['wpdb']->last_error = '';
            $GLOBALS['wpdb']->last_error = 'Sensitive database details';

            return null;
        });

        try {
            (new WpAuditExportMetadata($repository))->rule(12);
            $this->fail('A failed rule query must not look like a deleted rule.');
        } catch (AuditException $error) {
            $this->assertSame('Could not load audit export metadata.', $error->getMessage());
        }
    }

    public function testDeletedPostWithNoDatabaseFailureRemainsNull(): void
    {
        $GLOBALS['contentlatch_test_export']['missing'] = true;
        $GLOBALS['wpdb']->last_error = 'Earlier unrelated query failed';

        $this->assertNull($this->metadata()->post(42));
        $this->assertSame('', $GLOBALS['wpdb']->last_error);
        $this->assertSame(1, $GLOBALS['wpdb']->num_queries);
        $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['edit_calls']);
    }

    public function testPostReadFailureThrowsSafeExceptionAndCleansUp(): void
    {
        $GLOBALS['contentlatch_test_export']['read_error'] = 'Sensitive database details';
        try {
            $this->metadata()->post(42);
            $this->fail('Database errors must not look like a deleted post.');
        } catch (AuditException $error) {
            $this->assertSame('Could not load audit export metadata.', $error->getMessage());
        }
        $this->assertSame(array(array(42, 'posts'), array(42, 'post_meta')), $GLOBALS['contentlatch_test_export']['deleted']);
    }

    public function testFilterFailurePropagatesAndCleansUp(): void
    {
        $GLOBALS['contentlatch_test_export']['throw_on_edit'] = true;
        try {
            $this->metadata()->post(42);
            $this->fail('Filter errors must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('An edit-link filter failed.', $error->getMessage());
        }
        $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['cache']['posts']);
        $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['cache']['post_meta']);
    }

    public function testPostCachesDoNotAccumulateAcrossAnExport(): void
    {
        $metadata = $this->metadata();
        for ($id = 1; $id <= 1001; $id++) {
            $metadata->post($id);
            $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['cache']['posts']);
            $this->assertSame(array(), $GLOBALS['contentlatch_test_export']['cache']['post_meta']);
        }
    }

    private function metadata(): WpAuditExportMetadata
    {
        return new WpAuditExportMetadata($this->createStub(RuleRepositoryInterface::class));
    }
}
