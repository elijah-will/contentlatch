<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use ContentLatch\Admin\AuditAdminRequest;
use ContentLatch\Admin\AuditPage;
use PHPUnit\Framework\TestCase;

final class AuditAdminRequestTest extends TestCase
{
    public function testReservedPostTypeOnAuditPageIsRemappedBeforeWordPressReadsIt(): void
    {
        $get     = array('page' => AuditPage::SLUG, 'post_type' => 'recipe', 'severity' => 'fail');
        $request = $get;
        $post    = array('post_type' => 'recipe');

        $this->assertTrue(AuditAdminRequest::reservedPostTypeWouldBreakAuditPage($get));
        $this->assertTrue(AuditAdminRequest::apply($get, $request, $post));

        $this->assertSame('recipe', $get['cl_type']);
        $this->assertSame('recipe', $request['cl_type']);
        $this->assertArrayNotHasKey('post_type', $get);
        $this->assertArrayNotHasKey('post_type', $request);
        $this->assertArrayNotHasKey('post_type', $post);
        $this->assertSame('fail', $get['severity']);
        $this->assertSame(AuditPage::SLUG, $get['page']);
        $this->assertFalse(AuditAdminRequest::reservedPostTypeWouldBreakAuditPage($get));
    }

    public function testExistingSafeTypeParamIsKeptAndPostTypeIsStillRemoved(): void
    {
        $get     = array('page' => AuditPage::SLUG, 'cl_type' => 'product', 'post_type' => 'recipe');
        $request = $get;
        $post    = array();

        $this->assertTrue(AuditAdminRequest::apply($get, $request, $post));
        $this->assertSame('product', $get['cl_type']);
        $this->assertArrayNotHasKey('post_type', $get);
    }

    public function testNonAuditRequestsAreLeftAlone(): void
    {
        $get     = array('page' => 'contentlatch', 'post_type' => 'recipe');
        $request = $get;
        $post    = array();

        $this->assertFalse(AuditAdminRequest::apply($get, $request, $post));
        $this->assertSame('recipe', $get['post_type']);
    }

    public function testEditorAuditRunContextIsNotAnAuditPagePostType(): void
    {
        $get = array(
            'post'             => '42',
            'action'           => 'edit',
            'contentlatch_run' => '7',
            'contentlatch_field' => 'field_123abc',
        );

        $this->assertFalse(AuditAdminRequest::reservedPostTypeWouldBreakAuditPage($get));
        $this->assertArrayNotHasKey('post_type', $get);
        $this->assertArrayNotHasKey('page', $get);
    }
}
