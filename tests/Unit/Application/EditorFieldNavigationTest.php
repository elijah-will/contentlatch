<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\EditorFieldNavigation;
use PHPUnit\Framework\TestCase;

final class EditorFieldNavigationTest extends TestCase
{
    public function testSafeFieldKeysAreAppendedToEditUrls(): void
    {
        $url = EditorFieldNavigation::appendToEditUrl(
            'http://example.test/wp-admin/post.php?post=42&action=edit',
            'field_64f8a42a61f56'
        );

        $this->assertTrue(EditorFieldNavigation::isSafeFieldKey('field_64f8a42a61f56'));
        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_field=field_64f8a42a61f56',
            $url
        );
    }

    public function testUnsafeOrEmptyTargetsFallBackToTheOriginalEditUrl(): void
    {
        $edit = 'http://example.test/wp-admin/post.php?post=42&action=edit';

        $this->assertFalse(EditorFieldNavigation::isSafeFieldKey('field_nested/path'));
        $this->assertFalse(EditorFieldNavigation::isSafeFieldKey('description'));
        $this->assertFalse(EditorFieldNavigation::isSafeFieldKey('field_64f8a42a61f56"><script>'));
        $this->assertSame($edit, EditorFieldNavigation::appendToEditUrl($edit, 'not-a-field'));
        $this->assertSame('', EditorFieldNavigation::appendToEditUrl('', 'field_64f8a42a61f56'));
    }

    public function testRequestedFieldKeyReadsOnlySafeQueryValues(): void
    {
        $this->assertSame(
            'field_64f8a42a61f56',
            EditorFieldNavigation::requestedFieldKey(array(
                'contentguard_field' => 'field_64f8a42a61f56',
            ))
        );
        $this->assertSame('', EditorFieldNavigation::requestedFieldKey(array(
            'contentguard_field' => 'field_64f8a42a61f56/../x',
        )));
        $this->assertSame('', EditorFieldNavigation::requestedFieldKey(array()));
    }

    public function testAuditAdminUrlsAreNeverUsedAsEditDestinations(): void
    {
        $audit = 'http://example.test/wp-admin/admin.php?page=contentguard-audit&post_type=recipe';

        $this->assertTrue(EditorFieldNavigation::looksLikeAuditAdminUrl($audit));
        $this->assertSame('', EditorFieldNavigation::normalizeEditorUrl($audit));
        $this->assertSame('', EditorFieldNavigation::appendToEditUrl($audit, 'field_64f8a42a61f56'));
    }

    public function testNavigableFieldKeyRejectsUnsafeValues(): void
    {
        $this->assertSame('field_abc123', EditorFieldNavigation::navigableFieldKey('field_abc123'));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey(''));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey(null));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey('field_nested/path'));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey('acf[field_abc123]'));
        $this->assertSame('Go to field: Recipe Description', EditorFieldNavigation::goToFieldAria('Recipe Description'));
        $this->assertSame('Go to field: field', EditorFieldNavigation::goToFieldAria(''));
    }

    public function testAuditRunContextIsAppendedWithoutMessagesOrPostType(): void
    {
        $url = EditorFieldNavigation::appendToEditUrl(
            'http://example.test/wp-admin/post.php?post=42&action=edit',
            'field_64f8a42a61f56',
            7
        );

        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_field=field_64f8a42a61f56&contentguard_run=7',
            $url
        );
        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_run=7',
            EditorFieldNavigation::appendToEditUrl(
                'http://example.test/wp-admin/post.php?post=42&action=edit',
                'not-a-field',
                7
            )
        );
        $this->assertStringNotContainsString('post_type=', $url);
        $this->assertStringNotContainsString('page=contentguard-audit', $url);
    }

    public function testRequestedRunIdRejectsTamperedValues(): void
    {
        $this->assertSame(7, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '7',
        )));
        $this->assertSame(7, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => 7,
        )));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array()));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '0',
        )));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '-3',
        )));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '7abc',
        )));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '7"><script>',
        )));
        $this->assertSame(0, EditorFieldNavigation::sanitizeRunId('Description is required'));
    }
}
