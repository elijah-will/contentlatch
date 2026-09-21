<?php
/**
 * Audit table installation via dbDelta.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom tables have no Core API equivalent.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence checks are not object-cache data.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- dbDelta install and uninstall DROP TABLE are intentional.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers are $wpdb->prefix + ContentGuard table constants; SHOW/DROP use prepare (%s/%i); dbDelta CREATE strings are intentionally interpolated.

final class AuditSchema
{
    public const VERSION        = '2';
    public const OPTION_KEY     = 'contentguard_db_version';
    public const RUNS_TABLE     = 'contentguard_audit_runs';
    public const FINDINGS_TABLE = 'contentguard_audit_findings';
    public const LOCK_OPTION    = 'contentguard_audit_lock';
    public const CURSOR_COLUMN  = 'scan_cursor';

    public static function tableName(string $suffix): string
    {
        global $wpdb;

        $prefix = (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix))
            ? (string) $wpdb->prefix
            : 'wp_';

        return $prefix . $suffix;
    }

    public static function runsTable(): string
    {
        return self::tableName(self::RUNS_TABLE);
    }

    public static function findingsTable(): string
    {
        return self::tableName(self::FINDINGS_TABLE);
    }

    public static function install(): void
    {
        $installed = function_exists('get_option') ? (string) get_option(self::OPTION_KEY, '') : '';
        if ($installed === self::VERSION && self::tablesExist()) {
            return;
        }

        self::migrate();

        if (function_exists('update_option')) {
            update_option(self::OPTION_KEY, self::VERSION, false);
        }
    }

    public static function tablesExist(): bool
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }

        $runs     = self::runsTable();
        $findings = self::findingsTable();
        $likeRuns = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($runs) : $runs;
        $likeFindings = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($findings) : $findings;

        if (!method_exists($wpdb, 'prepare')) {
            return false;
        }

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $likeRuns)) === $runs
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $likeFindings)) === $findings;
    }

    public static function migrate(): void
    {
        if (!function_exists('dbDelta')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (defined('ABSPATH') && is_readable($upgrade)) {
                require_once $upgrade;
            }
        }

        if (!function_exists('dbDelta')) {
            return;
        }

        dbDelta(self::runsSql());
        dbDelta(self::findingsSql());
    }

    public static function drop(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'query')) {
            return;
        }

        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::findingsTable()));
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::runsTable()));

        if (function_exists('delete_option')) {
            delete_option(self::OPTION_KEY);
            delete_option(self::LOCK_OPTION);
        }
    }

    public static function runsSql(): string
    {
        $table   = self::runsTable();
        $charset = self::charsetCollate();

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- dbDelta requires interpolated table/charset; both are prefix + constants, not user input.
        return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
status varchar(20) NOT NULL,
started_at datetime NOT NULL,
finished_at datetime DEFAULT NULL,
heartbeat_at datetime NOT NULL,
posts_scanned bigint(20) unsigned NOT NULL DEFAULT 0,
posts_passed bigint(20) unsigned NOT NULL DEFAULT 0,
posts_warned bigint(20) unsigned NOT NULL DEFAULT 0,
posts_failed bigint(20) unsigned NOT NULL DEFAULT 0,
posts_not_evaluated bigint(20) unsigned NOT NULL DEFAULT 0,
posts_total bigint(20) unsigned NOT NULL DEFAULT 0,
scan_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
post_types text NOT NULL,
error_message varchar(255) DEFAULT NULL,
PRIMARY KEY  (id),
KEY status (status),
KEY heartbeat_at (heartbeat_at)
) {$charset};";
    }

    public static function findingsSql(): string
    {
        $table   = self::findingsTable();
        $charset = self::charsetCollate();

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- dbDelta requires interpolated table/charset; both are prefix + constants, not user input.
        return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
run_id bigint(20) unsigned NOT NULL,
post_id bigint(20) unsigned NOT NULL,
post_type varchar(20) NOT NULL,
rule_id varchar(64) NOT NULL,
field_key varchar(191) NOT NULL,
validation_id varchar(64) NOT NULL DEFAULT '',
code varchar(64) NOT NULL DEFAULT '',
severity varchar(20) NOT NULL,
message text NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY finding_identity (run_id,post_id,rule_id,field_key,validation_id),
KEY run_severity (run_id,severity),
KEY run_post (run_id,post_id),
KEY rule_id (rule_id)
) {$charset};";
    }

    private static function charsetCollate(): string
    {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_charset_collate')) {
            return $wpdb->get_charset_collate();
        }

        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}
