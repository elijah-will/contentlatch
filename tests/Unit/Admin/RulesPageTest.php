<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\RulesPage;
use ContentGuard\Application\Audit\AuditRuleImpact;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RulesPageTest extends TestCase
{
    public function testRuleImpactLabelUsesAffectedPostsFromLatestCompleteAudit(): void
    {
        $failRule    = RuleFactory::rule(array('severity' => RuleSeverity::Fail));
        $warningRule = RuleFactory::rule(array('id' => 2, 'severity' => RuleSeverity::Warning));
        $complete    = new AuditRun(
            4,
            AuditRunStatus::Complete,
            '2026-09-05 00:00:00',
            '2026-09-05 00:05:00',
            '2026-09-05 00:05:00',
            10,
            4,
            3,
            3,
            0,
            10,
            10,
            1,
            array('product'),
            null
        );

        $this->assertSame('No completed audit yet', RulesPage::ruleImpactLabel(null, $failRule, null));
        $this->assertSame('No findings in latest audit', RulesPage::ruleImpactLabel($complete, $failRule, null));
        $this->assertSame(
            '1 content item failing',
            RulesPage::ruleImpactLabel($complete, $failRule, new AuditRuleImpact(1, 3, 1))
        );
        $this->assertSame(
            '6 content items failing',
            RulesPage::ruleImpactLabel($complete, $failRule, new AuditRuleImpact(1, 9, 6))
        );
        $this->assertSame(
            '3 content items with warnings',
            RulesPage::ruleImpactLabel($complete, $warningRule, new AuditRuleImpact(2, 3, 3))
        );
    }
}
