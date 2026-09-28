<?php
/**
 * Human-readable status labels for the admin UI.
 *
 * Stored values stay unchanged. This is presentation only.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Domain\RuleStatus;

final class StatusPresentation
{
    public static function label(string $status): string
    {
        return match ($status) {
            'active'          => __('Active', 'contentlatch'),
            'inactive'        => __('Inactive', 'contentlatch'),
            'fail', 'blocking' => __('Blocking', 'contentlatch'),
            'warning'         => __('Warning', 'contentlatch'),
            'complete', 'completed' => __('Completed', 'contentlatch'),
            'running'         => __('Running', 'contentlatch'),
            'cancelled'       => __('Cancelled', 'contentlatch'),
            'failed'          => __('Failed', 'contentlatch'),
            'need_attention'  => __('Need attention', 'contentlatch'),
            'need_review'     => __('Need review', 'contentlatch'),
            'pending'         => __('Pending', 'contentlatch'),
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
