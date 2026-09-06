<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application\Audit;

use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\Audit\AuditFindingGroup;
use ContentGuard\Domain\RuleSeverity;
use PHPUnit\Framework\TestCase;

final class AuditFindingGroupTest extends TestCase
{
    public function testSameContentRuleAndFieldAreGroupedWithoutChangingIdentity(): void
    {
        $first  = $this->finding(1, 'Page ID is required.', 'v1');
        $second = $this->finding(2, 'Page ID must be at least 10 characters.', 'v2');
        $other  = $this->finding(3, 'Description is required.', 'v1', array(
            'fieldKey' => 'field_desc',
        ));

        $groups = AuditFindingGroup::group(array($first, $second, $other));

        $this->assertCount(2, $groups);
        $this->assertTrue($groups[0]->isMultiple());
        $this->assertSame(2, $groups[0]->count());
        $this->assertSame(array($first, $second), $groups[0]->findings);
        $this->assertSame(
            array('Page ID is required.', 'Page ID must be at least 10 characters.'),
            $groups[0]->messages()
        );
        $this->assertSame(array('blocking'), $groups[0]->statuses());
        $this->assertFalse($groups[1]->isMultiple());
        $this->assertSame($other, $groups[1]->first());
    }

    public function testMixedSeverityKeepsBothLabels(): void
    {
        $fail    = $this->finding(1, 'Required.', 'v1');
        $warning = $this->finding(2, 'Looks thin.', 'v2', array(
            'severity' => RuleSeverity::Warning,
        ));

        $group = AuditFindingGroup::group(array($fail, $warning))[0];

        $this->assertSame(array('blocking', 'warning'), $group->statuses());
        $this->assertSame('blocking', $group->primaryStatus());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function finding(int $id, string $message, string $validationId, array $overrides = array()): AuditFinding
    {
        return new AuditFinding(
            $id,
            7,
            (int) ($overrides['postId'] ?? 42),
            'recipe',
            $overrides['ruleId'] ?? 15,
            (string) ($overrides['fieldKey'] ?? 'field_page'),
            $validationId,
            'required',
            $overrides['severity'] ?? RuleSeverity::Fail,
            $message,
            '2026-01-01 00:00:00'
        );
    }
}
