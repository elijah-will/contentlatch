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

use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;

final class StatusPresentation
{
    public static function label(string $status): string
    {
        return match ($status) {
            'active'          => I18n::translate('Active'),
            'inactive'        => I18n::translate('Inactive'),
            'fail', 'blocking' => I18n::translate('Blocking'),
            'warning'         => I18n::translate('Warning'),
            'complete', 'completed' => I18n::translate('Completed'),
            'running'         => I18n::translate('Running'),
            'cancelled'       => I18n::translate('Cancelled'),
            'failed'          => I18n::translate('Failed'),
            'need_attention'  => I18n::translate('Need attention'),
            'need_review'     => I18n::translate('Need review'),
            'pending'         => I18n::translate('Pending'),
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
