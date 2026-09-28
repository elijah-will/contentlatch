<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application\Audit;

use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditFindingGroup;
use ContentLatch\Application\AuditPresentation;
use ContentLatch\Application\ContentEvaluator;
use ContentLatch\Application\EditorFieldNavigation;
use ContentLatch\Domain\ArrayValueProvider;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Infrastructure\WordPress\AuditSchema;
use ContentLatch\Tests\Support\AcfCloneFixtures;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AcfCloneAuditTest extends TestCase
{
    public function testClonedFindingsKeepDistinctFieldKeysAndLabels(): void
    {
        $rules = array(
            RuleFactory::rule(array(
                'id'          => 1,
                'name'        => 'Shared title required',
                'postType'    => 'page',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'id'    => 'v1',
                        'field' => AcfCloneFixtures::cloneATitleRef(),
                    )),
                ),
            )),
            RuleFactory::rule(array(
                'id'          => 2,
                'name'        => 'Hero title required',
                'postType'    => 'page',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'id'    => 'v1',
                        'field' => AcfCloneFixtures::cloneBTitleRef(),
                    )),
                ),
            )),
        );

        $evaluation = (new ContentEvaluator(
            new \ContentLatch\Tests\Support\InMemoryRuleRepository($rules),
            RuleEngine::v1()
        ))->evaluate(
            9,
            'page',
            new ArrayValueProvider(array(
                AcfCloneFixtures::cloneATitlePosted() => '',
                AcfCloneFixtures::cloneBTitleId()    => '',
                AcfCloneFixtures::TITLE              => 'Source is filled',
            ))
        );

        $fieldIds = array_map(static fn ($result): string => (string) $result->fieldId, $evaluation->results);
        $this->assertContains(AcfCloneFixtures::cloneATitlePosted(), $fieldIds);
        $this->assertContains(AcfCloneFixtures::cloneBTitleId(), $fieldIds);
        $this->assertNotContains(AcfCloneFixtures::TITLE, $fieldIds);

        $this->assertSame(
            'Shared Content → Title',
            AuditPresentation::fieldLabel($rules[0], AcfCloneFixtures::cloneATitlePosted())
        );
        $this->assertSame(
            'Hero Clone → Title',
            AuditPresentation::fieldLabel($rules[1], AcfCloneFixtures::cloneBTitleId())
        );
    }

    public function testHistoricalNonCloneFindingStillMatchesTheOriginalKey(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => RuleFactory::field(AcfCloneFixtures::TITLE, 'title', 'Title'),
                )),
            ),
        ));

        $this->assertSame('Title', AuditPresentation::fieldLabel($rule, AcfCloneFixtures::TITLE));
        $this->assertSame(
            AcfCloneFixtures::cloneATitlePosted(),
            AuditPresentation::fieldLabel($rule, AcfCloneFixtures::cloneATitlePosted())
        );
    }

    public function testGroupingDoesNotCollapseTwoClonesOfTheSameSource(): void
    {
        $groups = AuditFindingGroup::group(array(
            $this->finding(AcfCloneFixtures::cloneATitlePosted(), 'Shared is required.'),
            $this->finding(AcfCloneFixtures::cloneBTitleId(), 'Hero is required.'),
        ));

        $this->assertCount(1, $groups);
        $byField = $groups[0]->findingsByField();
        $this->assertCount(2, $byField);
        $this->assertArrayHasKey(AcfCloneFixtures::cloneATitlePosted(), $byField);
        $this->assertArrayHasKey(AcfCloneFixtures::cloneBTitleId(), $byField);
    }

    public function testResolutionIdsAreNavigableWithoutASchemaChange(): void
    {
        $this->assertTrue(EditorFieldNavigation::isSafeFieldKey(AcfCloneFixtures::cloneATitlePosted()));
        $this->assertStringContainsString(
            'contentlatch_field=' . AcfCloneFixtures::cloneATitlePosted(),
            EditorFieldNavigation::appendToEditUrl(
                'http://example.test/wp-admin/post.php?post=9&action=edit',
                AcfCloneFixtures::cloneATitlePosted()
            )
        );
        $sql = AuditSchema::findingsSql();
        $this->assertStringContainsString('field_key varchar(191)', $sql);
        $this->assertStringNotContainsString('clone_key', $sql);
        $this->assertStringNotContainsString('resolution_id', $sql);
    }

    private function finding(string $fieldKey, string $message): AuditFinding
    {
        return new AuditFinding(
            0,
            1,
            9,
            'page',
            1,
            $fieldKey,
            'v1',
            'required',
            RuleSeverity::Fail,
            $message,
            '2026-09-07 12:00:00'
        );
    }
}
