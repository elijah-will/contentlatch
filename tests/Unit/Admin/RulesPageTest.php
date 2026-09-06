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
use ContentGuard\Domain\RuleStatus;
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

    public function testAllRulesInactiveIgnoresAnEmptyList(): void
    {
        $this->assertFalse(RulesPage::allRulesInactive(array()));
        $this->assertFalse(RulesPage::allRulesInactive(array(
            RuleFactory::rule(array('status' => RuleStatus::Active)),
        )));
        $this->assertFalse(RulesPage::allRulesInactive(array(
            RuleFactory::rule(array('status' => RuleStatus::Inactive)),
            RuleFactory::rule(array('id' => 2, 'status' => RuleStatus::Active)),
        )));
        $this->assertTrue(RulesPage::allRulesInactive(array(
            RuleFactory::rule(array('status' => RuleStatus::Inactive)),
            RuleFactory::rule(array('id' => 2, 'status' => RuleStatus::Inactive)),
        )));
    }

    public function testSortForListPutsActiveRulesBeforeInactiveAndKeepsRelativeOrder(): void
    {
        $firstInactive = RuleFactory::rule(array(
            'id'     => 10,
            'name'   => 'Oldest inactive',
            'status' => RuleStatus::Inactive,
        ));
        $firstActive = RuleFactory::rule(array(
            'id'     => 11,
            'name'   => 'First active',
            'status' => RuleStatus::Active,
        ));
        $secondInactive = RuleFactory::rule(array(
            'id'     => 12,
            'name'   => 'Newer inactive',
            'status' => RuleStatus::Inactive,
        ));
        $secondActive = RuleFactory::rule(array(
            'id'     => 13,
            'name'   => 'Second active',
            'status' => RuleStatus::Active,
        ));

        $sorted = RulesPage::sortForList(array(
            $firstInactive,
            $firstActive,
            $secondInactive,
            $secondActive,
        ));

        $this->assertSame(array(11, 13, 10, 12), array_map(static fn ($rule) => $rule->id, $sorted));
        $this->assertSame(array('active', 'active', 'inactive', 'inactive'), array_map(
            static fn ($rule) => $rule->status->value,
            $sorted
        ));
        $this->assertSame('First active', $sorted[0]->name);
        $this->assertSame('Second active', $sorted[1]->name);
        $this->assertSame('Oldest inactive', $sorted[2]->name);
        $this->assertSame('Newer inactive', $sorted[3]->name);
        $this->assertCount(4, $sorted);
        $this->assertSame($firstActive, $sorted[0]);
        $this->assertSame($secondInactive, $sorted[3]);
    }

    public function testAllInactiveNoticeExplainsEnforcement(): void
    {
        $notice = RulesPage::allInactiveNotice();

        $this->assertStringContainsString('None of these rules are active', $notice);
        $this->assertStringContainsString('not currently being enforced', $notice);
        $this->assertStringContainsString('Activate a rule to allow ContentGuard to validate content', $notice);
    }
}
