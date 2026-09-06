<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\AdminNotice;
use PHPUnit\Framework\TestCase;

final class AdminNoticeTest extends TestCase
{
    public function testSuccessfulCreateUsesSuccessPresentation(): void
    {
        $args = AdminNotice::queryArgs(true, 'Rule added.');
        $notice = AdminNotice::fromQuery($args);

        $this->assertSame('success', $args['contentguard_notice']);
        $this->assertSame('Rule added.', $args['contentguard_msg']);
        $this->assertSame('success', $notice['type']);
        $this->assertSame('Rule added.', $notice['message']);
        $this->assertStringContainsString('notice-success', AdminNotice::cssClass($notice['type']));
        $this->assertStringNotContainsString('notice-error', AdminNotice::cssClass($notice['type']));
    }

    public function testFailedCreateUsesErrorPresentation(): void
    {
        $args = AdminNotice::queryArgs(false, 'We could not add this rule. Rule name is required.');
        $notice = AdminNotice::fromQuery($args);

        $this->assertSame('error', $args['contentguard_notice']);
        $this->assertSame('error', $notice['type']);
        $this->assertStringStartsWith('We could not add this rule.', $notice['message']);
        $this->assertStringNotContainsString('Rule added.', $notice['message']);
        $this->assertSame('notice notice-error is-dismissible', AdminNotice::cssClass($notice['type']));
        $this->assertStringNotContainsString('notice-success', AdminNotice::cssClass($notice['type']));
    }

    public function testLegacyUpdatedTypeIsStillSuccess(): void
    {
        $notice = AdminNotice::fromQuery(array(
            'contentguard_notice' => 'updated',
            'contentguard_msg'    => 'Rule%20added.',
        ));

        $this->assertSame('success', $notice['type']);
        $this->assertSame('Rule added.', $notice['message']);
    }

    public function testDoubleEncodedMessageStillReads(): void
    {
        $notice = AdminNotice::fromQuery(array(
            'contentguard_notice' => 'success',
            'contentguard_msg'    => rawurlencode(rawurlencode('Rule added.')),
        ));

        $this->assertSame('Rule added.', $notice['message']);
        $this->assertSame('success', $notice['type']);
    }

    public function testFailedSaveUrlGetsNoticeAnchorAndSuccessDoesNot(): void
    {
        $this->assertSame('contentguard-rule-notice', AdminNotice::TARGET_ID);
        $this->assertSame(
            'http://example.test/wp-admin/admin.php?page=contentguard&action=new#contentguard-rule-notice',
            AdminNotice::appendTarget('http://example.test/wp-admin/admin.php?page=contentguard&action=new', false)
        );
        $this->assertSame(
            'http://example.test/wp-admin/admin.php?page=contentguard&rule=7#contentguard-rule-notice',
            AdminNotice::appendTarget('http://example.test/wp-admin/admin.php?page=contentguard&rule=7#elsewhere', false)
        );
        $this->assertSame(
            'http://example.test/wp-admin/admin.php?page=contentguard&rule=7',
            AdminNotice::appendTarget('http://example.test/wp-admin/admin.php?page=contentguard&rule=7', true)
        );
    }
}
