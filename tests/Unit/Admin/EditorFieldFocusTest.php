<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\EditorFieldFocus;
use PHPUnit\Framework\TestCase;

final class EditorFieldFocusTest extends TestCase
{
    public function testEnqueueHappensOnEditorScreensEvenWithoutAFieldKey(): void
    {
        $this->assertTrue(EditorFieldFocus::shouldEnqueue('post.php', array(
            'contentguard_field' => 'field_64f8a42a61f56',
        )));
        $this->assertTrue(EditorFieldFocus::shouldEnqueue('post-new.php', array(
            'contentguard_field' => 'field_abc123',
        )));
        $this->assertTrue(EditorFieldFocus::shouldEnqueue('post.php', array()));
        $this->assertTrue(EditorFieldFocus::shouldEnqueue('post.php', array(
            'contentguard_field' => 'not-safe',
        )));
        $this->assertFalse(EditorFieldFocus::shouldEnqueue('edit.php', array(
            'contentguard_field' => 'field_64f8a42a61f56',
        )));
    }

    public function testAutoNavigateUsesOnlyASafeUrlFieldKey(): void
    {
        $this->assertSame(
            'field_64f8a42a61f56',
            EditorFieldFocus::autoNavigateFieldKey(array(
                'contentguard_field' => 'field_64f8a42a61f56',
            ))
        );
        $this->assertSame('', EditorFieldFocus::autoNavigateFieldKey(array()));
        $this->assertSame('', EditorFieldFocus::autoNavigateFieldKey(array(
            'contentguard_field' => 'not-safe',
        )));
        $this->assertSame('title', EditorFieldFocus::autoNavigateFieldKey(array(
            'contentguard_field' => 'title',
        )));
        $this->assertSame('featured_image', EditorFieldFocus::autoNavigateFieldKey(array(
            'contentguard_field' => 'featured_image',
        )));

        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorFieldFocus.php');
        $this->assertStringContainsString('EditorCoreNavigation::clientConfig', $php);
        $this->assertStringContainsString("'surface'", $php);
    }
}
