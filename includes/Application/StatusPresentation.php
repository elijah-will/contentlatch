<?php
/**
 * Human-readable status labels for the admin UI.
 *
 * Stored values stay unchanged. This is presentation only.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

defined('ABSPATH') || exit;

use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;

final class StatusPresentation
{
    public static function label(string $status): string
    {
        return match ($status) {
            'active'          => __('Active', 'contentguard'),
            'inactive'        => __('Inactive', 'contentguard'),
            'fail', 'blocking' => __('Blocking', 'contentguard'),
            'warning'         => __('Warning', 'contentguard'),
            'complete', 'completed' => __('Completed', 'contentguard'),
            'running'         => __('Running', 'contentguard'),
            'cancelled'       => __('Cancelled', 'contentguard'),
            'failed'          => __('Failed', 'contentguard'),
            'need_attention'  => __('Need attention', 'contentguard'),
            'need_review'     => __('Need review', 'contentguard'),
            'pending'         => __('Pending', 'contentguard'),
            default           => $status,
        };
    }

    /**
     * Visual variant for the status pill. Never used without a text label.
     */
    public static function variant(string $status): string
    {
        return match ($status) {
            'active', 'complete', 'completed' => 'success',
            'fail', 'blocking', 'failed', 'need_attention' => 'danger',
            'warning', 'need_review', 'pending' => 'caution',
            'running' => 'info',
            default   => 'neutral',
        };
    }

    public static function fromRuleStatus(RuleStatus $status): string
    {
        return $status->value;
    }

    public static function fromSeverity(RuleSeverity $severity): string
    {
        return $severity === RuleSeverity::Fail ? 'blocking' : $severity->value;
    }

    public static function fromAuditRunStatus(AuditRunStatus $status): string
    {
        return $status === AuditRunStatus::Complete ? 'completed' : $status->value;
    }
}
