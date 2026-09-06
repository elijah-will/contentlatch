<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Infrastructure\WordPress\AuditSchema;
use PHPUnit\Framework\TestCase;

final class AuditSchemaTest extends TestCase
{
    public function testSqlUsesPrefixAndDbDeltaConventions(): void
    {
        $runs = AuditSchema::runsSql();
        $findings = AuditSchema::findingsSql();

        $this->assertStringContainsString('wp_contentguard_audit_runs', $runs);
        $this->assertStringContainsString('PRIMARY KEY  (id)', $runs);
        $this->assertStringContainsString('heartbeat_at', $runs);
        $this->assertStringContainsString('scan_cursor', $runs);
        $this->assertStringContainsString('finished_at datetime DEFAULT NULL', $runs);
        $this->assertStringContainsString('error_message varchar(255) DEFAULT NULL', $runs);
        $this->assertStringContainsString('KEY status (status)', $runs);

        $this->assertStringContainsString('wp_contentguard_audit_findings', $findings);
        $this->assertStringContainsString('PRIMARY KEY  (id)', $findings);
        $this->assertStringContainsString(
            'UNIQUE KEY finding_identity (run_id,post_id,rule_id,field_key,validation_id)',
            $findings
        );
        $this->assertStringContainsString('KEY run_severity (run_id,severity)', $findings);
        $this->assertStringContainsString('KEY run_post (run_id,post_id)', $findings);
        $this->assertStringContainsString('KEY rule_id (rule_id)', $findings);
        $this->assertStringNotContainsString('`', $runs);
        $this->assertStringNotContainsString('`', $findings);
    }

    public function testSchemaVersionAndLockOptionNames(): void
    {
        $this->assertSame('2', AuditSchema::VERSION);
        $this->assertSame('contentguard_db_version', AuditSchema::OPTION_KEY);
        $this->assertSame('contentguard_audit_lock', AuditSchema::LOCK_OPTION);
        $this->assertSame('scan_cursor', AuditSchema::CURSOR_COLUMN);
    }

    public function testRunsSqlDoesNotUseUnquotedMysqlReservedCursorColumn(): void
    {
        $sql = AuditSchema::runsSql();

        $this->assertMatchesRegularExpression('/scan_cursor\s+bigint/', $sql);
        $this->assertDoesNotMatchRegularExpression('/^\s*cursor\s+/m', $sql);
        $this->assertFalse(AuditSchema::tablesExist());
    }
}
