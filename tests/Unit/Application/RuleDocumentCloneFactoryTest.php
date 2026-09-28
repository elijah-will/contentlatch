<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application;

use ContentLatch\Application\RuleDocumentFactory;
use ContentLatch\Application\RuleDocumentValidator;
use ContentLatch\Domain\Exception\InvalidRuleException;
use ContentLatch\Tests\Support\AcfCloneFixtures;
use PHPUnit\Framework\TestCase;

final class RuleDocumentCloneFactoryTest extends TestCase
{
    public function testCloneFieldPersistsOriginalKeyAndCloneMetadata(): void
    {
        $rule = $this->factory()->fromAdminInput(array(
            'name'      => 'Shared title required',
            'post_type' => 'page',
            'status'    => 'active',
            'severity'  => 'fail',
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::cloneATitlePosted(),
                    'type'      => 'required',
                ),
            ),
        ));

        $field = $rule->validations[0]->field;
        $this->assertSame(1, $rule->schemaVersion);
        $this->assertSame(AcfCloneFixtures::TITLE, $field->key);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $field->clone);
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $field->resolutionId());
        $this->assertSame('Shared Content → Title', $field->label);
        $this->assertSame(
            array(AcfCloneFixtures::CLONE_A, AcfCloneFixtures::cloneATitlePosted()),
            $field->path
        );

        $loaded = RuleDocumentValidator::v1()->validateArray($rule->toArray());
        $this->assertSame(1, $loaded->schemaVersion);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $loaded->validations[0]->field->clone);
        $this->assertArrayNotHasKey('clone', $this->factory()->fromAdminInput(array(
            'name'      => 'Direct title',
            'post_type' => 'page',
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::TITLE,
                    'type'      => 'required',
                ),
            ),
        ))->validations[0]->field->toArray());
    }

    public function testTopLevelCloneMayBeUsedAsWhenField(): void
    {
        $rule = $this->factory()->fromAdminInput(array(
            'name'      => 'When shared title',
            'post_type' => 'page',
            'conditions' => array(
                array(
                    'field_key' => AcfCloneFixtures::cloneATitlePosted(),
                    'operator'  => 'is_not_empty',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::TITLE,
                    'type'      => 'required',
                ),
            ),
        ));

        $this->assertSame(AcfCloneFixtures::CLONE_A, $rule->conditions[0]->field->clone);
        $this->assertSame(AcfCloneFixtures::TITLE, $rule->validations[0]->field->key);
        $this->assertSame('', $rule->validations[0]->field->clone);
    }

    public function testRepeaterAndFlexibleCloneChildrenCanBeWhenFields(): void
    {
        $factory = $this->factory();

        $repeater = $factory->fromAdminInput(array(
            'name'      => 'Repeater clone when',
            'post_type' => 'page',
            'conditions' => array(
                array(
                    'field_key' => \ContentLatch\Domain\FieldRef::resolutionIdFor(
                        AcfCloneFixtures::CLONE_REP,
                        AcfCloneFixtures::TITLE
                    ),
                    'operator'  => 'contains',
                    'operand'   => 'chicken',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::TITLE,
                    'type'      => 'required',
                ),
            ),
        ));
        $this->assertSame('contains', $repeater->conditions[0]->operator);
        $this->assertSame('chicken', $repeater->conditions[0]->operand);
        $this->assertSame('repeater', $repeater->conditions[0]->field->container);
        $this->assertSame(AcfCloneFixtures::CLONE_REP, $repeater->conditions[0]->field->clone);

        $flex = $factory->fromAdminInput(array(
            'name'      => 'Flex clone when',
            'post_type' => 'page',
            'conditions' => array(
                array(
                    'field_key' => \ContentLatch\Domain\FieldRef::resolutionIdFor(
                        AcfCloneFixtures::CLONE_FLEX,
                        AcfCloneFixtures::TITLE
                    ),
                    'operator'  => 'is_empty',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::TITLE,
                    'type'      => 'required',
                ),
            ),
        ));
        $this->assertSame('is_empty', $flex->conditions[0]->operator);
        $this->assertSame('flexible_content', $flex->conditions[0]->field->container);
        $this->assertSame(AcfCloneFixtures::CLONE_FLEX, $flex->conditions[0]->field->clone);
    }

    public function testArbitraryClonePathCannotBeSupplied(): void
    {
        $this->expectException(InvalidRuleException::class);
        $this->factory()->fromAdminInput(array(
            'name'      => 'Forged path',
            'post_type' => 'page',
            'validations' => array(
                array(
                    'field_key' => 'field_not_in_catalog',
                    'type'      => 'required',
                ),
            ),
        ));
    }

    private function factory(): RuleDocumentFactory
    {
        $catalog = AcfCloneFixtures::pageCatalog();

        return RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page'),
            static function (string $postType) use ($catalog): array {
                $fields = array();
                foreach ($catalog->fieldsForPostType($postType) as $field) {
                    $fields[] = $field->toCatalogArray();
                }

                return $fields;
            }
        );
    }
}
