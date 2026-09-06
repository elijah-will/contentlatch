<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\StatusPresentation;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;
use PHPUnit\Framework\TestCase;

final class StatusPresentationTest extends TestCase
{
    public function testRequiredStatusesHaveReadableLabels(): void
    {
        $this->assertSame('Active', StatusPresentation::label('active'));
        $this->assertSame('Inactive', StatusPresentation::label('inactive'));
        $this->assertSame('Blocking', StatusPresentation::label('fail'));
        $this->assertSame('Blocking', StatusPresentation::label('blocking'));
        $this->assertSame('Warning', StatusPresentation::label('warning'));
        $this->assertSame('Completed', StatusPresentation::label('complete'));
        $this->assertSame('Completed', StatusPresentation::label('completed'));
        $this->assertSame('Running', StatusPresentation::label('running'));
        $this->assertSame('Cancelled', StatusPresentation::label('cancelled'));
        $this->assertSame('Failed', StatusPresentation::label('failed'));
        $this->assertSame('Need attention', StatusPresentation::label('need_attention'));
        $this->assertSame('Need review', StatusPresentation::label('need_review'));
    }

    public function testStoredSeverityAndAuditFailureStayDistinct(): void
    {
        $this->assertSame('blocking', StatusPresentation::fromSeverity(RuleSeverity::Fail));
        $this->assertSame('warning', StatusPresentation::fromSeverity(RuleSeverity::Warning));
        $this->assertSame('Blocking', StatusPresentation::label(StatusPresentation::fromSeverity(RuleSeverity::Fail)));
        $this->assertSame('Failed', StatusPresentation::label(StatusPresentation::fromAuditRunStatus(AuditRunStatus::Failed)));
    }

    public function testRuleAndRunStatusesMapWithoutChangingStoredValues(): void
    {
        $this->assertSame('active', StatusPresentation::fromRuleStatus(RuleStatus::Active));
        $this->assertSame('inactive', StatusPresentation::fromRuleStatus(RuleStatus::Inactive));
        $this->assertSame('completed', StatusPresentation::fromAuditRunStatus(AuditRunStatus::Complete));
        $this->assertSame('running', StatusPresentation::fromAuditRunStatus(AuditRunStatus::Running));
        $this->assertSame('cancelled', StatusPresentation::fromAuditRunStatus(AuditRunStatus::Cancelled));
        $this->assertSame('failed', StatusPresentation::fromAuditRunStatus(AuditRunStatus::Failed));
    }

    public function testVariantsNeverReplaceTheTextLabel(): void
    {
        $this->assertSame('success', StatusPresentation::variant('active'));
        $this->assertSame('danger', StatusPresentation::variant('blocking'));
        $this->assertSame('caution', StatusPresentation::variant('warning'));
        $this->assertSame('info', StatusPresentation::variant('running'));
        $this->assertSame('neutral', StatusPresentation::variant('inactive'));
        $this->assertSame('neutral', StatusPresentation::variant('unknown'));
        $this->assertSame('unknown', StatusPresentation::label('unknown'));
    }
}
