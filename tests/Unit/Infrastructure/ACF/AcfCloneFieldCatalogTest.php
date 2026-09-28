<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Domain\FieldRef;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Tests\Support\AcfCloneFixtures;
use PHPUnit\Framework\TestCase;

final class AcfCloneFieldCatalogTest extends TestCase
{
    public function testSeamlessAndGroupClonesAreCataloguedAsDistinctTargets(): void
    {
        $catalog = AcfCloneFixtures::pageCatalog();
        $byId    = AcfCloneFixtures::byResolutionId($catalog);

        $a = $byId[AcfCloneFixtures::cloneATitlePosted()];
        $b = $byId[AcfCloneFixtures::cloneBTitleId()];

        $this->assertSame(AcfCloneFixtures::TITLE, $a->key);
        $this->assertSame(AcfCloneFixtures::TITLE, $b->key);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $a->clone);
        $this->assertSame(AcfCloneFixtures::CLONE_B, $b->clone);
        $this->assertNotSame($a->resolutionId(), $b->resolutionId());
        $this->assertSame(
            array(AcfCloneFixtures::CLONE_A, AcfCloneFixtures::cloneATitlePosted()),
            $a->path
        );
        $this->assertSame(
            array(AcfCloneFixtures::CLONE_B, AcfCloneFixtures::TITLE),
            $b->path
        );
        $this->assertSame('Shared Content → Title', $a->breadcrumb());
        $this->assertSame('Hero Clone → Title', $b->breadcrumb());
        $this->assertSame('shared_a_title', $a->name);
        $this->assertSame(FieldRef::CONTAINER_CLONE, $a->container);
        $this->assertSame('seamless', $a->cloneDisplay);
        $this->assertSame('group', $b->cloneDisplay);
    }

    public function testPrefixFieldNamesAndLabelsArePreserved(): void
    {
        $catalog = AcfCloneFixtures::catalogFor(array(AcfCloneFixtures::seamlessCloneA()));
        $field   = AcfCloneFixtures::byResolutionId($catalog)[AcfCloneFixtures::cloneATitlePosted()];

        $this->assertSame('shared_a_title', $field->name);
        $this->assertSame('Shared Title', $field->label);
        $this->assertSame('Title', $field->pathLabels[count($field->pathLabels) - 1]);
        $this->assertSame('Shared Content → Title', $field->breadcrumb());
    }

    public function testCloneOfGroupCataloguesSupportedScalarLeaves(): void
    {
        $field = AcfCloneFixtures::byResolutionId(AcfCloneFixtures::pageCatalog())[
            FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_GROUP, AcfCloneFixtures::INGREDIENTS)
        ];

        $this->assertSame(AcfCloneFixtures::INGREDIENTS, $field->key);
        $this->assertSame(AcfCloneFixtures::CLONE_GROUP, $field->clone);
        $this->assertSame(
            array(
                AcfCloneFixtures::CLONE_GROUP,
                AcfCloneFixtures::cloneGroupDetailsPosted(),
                AcfCloneFixtures::INGREDIENTS,
            ),
            $field->path
        );
        $this->assertSame('Product Details → Product Details → Ingredients', $field->breadcrumb());
    }

    public function testCloneInsideRepeaterAndFlexibleKeepsParentContainer(): void
    {
        $byId = AcfCloneFixtures::byResolutionId(AcfCloneFixtures::pageCatalog());

        $repeater = $byId[FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_REP, AcfCloneFixtures::TITLE)];
        $this->assertSame(FieldRef::CONTAINER_REPEATER, $repeater->container);
        $this->assertSame(AcfCloneFixtures::REPEATER, $repeater->repeaterKey);
        $this->assertSame(AcfCloneFixtures::CLONE_REP, $repeater->clone);
        $this->assertSame(
            array(
                AcfCloneFixtures::REPEATER,
                AcfCloneFixtures::CLONE_REP,
                AcfCloneFixtures::cloneRepTitlePosted(),
            ),
            $repeater->path
        );

        $flex = $byId[FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_FLEX, AcfCloneFixtures::TITLE)];
        $this->assertSame(FieldRef::CONTAINER_FLEXIBLE, $flex->container);
        $this->assertSame('hero', $flex->layout);
        $this->assertSame(AcfCloneFixtures::CLONE_FLEX, $flex->clone);

        $flexGroup = $byId[FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_FLEX, AcfCloneFixtures::INGREDIENTS)];
        $this->assertSame(FieldRef::CONTAINER_FLEXIBLE, $flexGroup->container);
        $this->assertSame(
            array(
                AcfCloneFixtures::FLEX,
                AcfCloneFixtures::CLONE_FLEX,
                AcfCloneFixtures::cloneFlexGroupPosted(),
                AcfCloneFixtures::INGREDIENTS,
            ),
            $flexGroup->path
        );
    }

    public function testSeamlessSplicedChildrenDoNotLeakAsOrdinaryTopLevelFields(): void
    {
        $catalog = AcfCloneFixtures::catalogFor(AcfCloneFixtures::splicedSeamlessLeak());
        $fields  = $catalog->fieldsForPostType('page');
        $byId    = AcfCloneFixtures::byResolutionId($catalog);

        $this->assertArrayHasKey(AcfCloneFixtures::TITLE, $byId);
        $this->assertSame('', $byId[AcfCloneFixtures::TITLE]->clone);
        $this->assertSame(array(), $byId[AcfCloneFixtures::TITLE]->path);

        $cloned = $byId[AcfCloneFixtures::cloneATitlePosted()];
        $this->assertSame(AcfCloneFixtures::CLONE_A, $cloned->clone);
        $this->assertSame(AcfCloneFixtures::TITLE, $cloned->key);

        foreach ($fields as $field) {
            if ($field->clone === '') {
                $this->assertNotSame(AcfCloneFixtures::cloneATitlePosted(), $field->key);
            }
        }
    }

    public function testUnsupportedCloneChildrenAreExcluded(): void
    {
        $keys = array_map(
            static fn ($field): string => $field->key,
            AcfCloneFixtures::catalogFor(array(AcfCloneFixtures::unsupportedCloneChildren()))->fieldsForPostType('page')
        );

        $this->assertSame(array(), $keys);
        $this->assertNotContains('field_inner_clone', $keys);
        $this->assertNotContains('field_cloned_row_title', $keys);
        $this->assertNotContains('field_cloned_flex_title', $keys);
        $this->assertNotContains('field_cloned_gallery', $keys);
    }

    public function testDirectSourceFieldRemainsSelectableBesideItsClones(): void
    {
        $types = AcfCloneFixtures::pageCatalog()->fieldTypesForPostType('page');

        $this->assertSame('text', $types[AcfCloneFixtures::TITLE]);
        $this->assertSame('text', $types[AcfCloneFixtures::cloneATitlePosted()]);
        $this->assertSame('text', $types[AcfCloneFixtures::cloneBTitleId()]);
        $this->assertArrayHasKey(
            FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_GROUP, AcfCloneFixtures::INGREDIENTS),
            $types
        );
    }

    public function testNestedResolutionMapsAreKeyedByResolutionId(): void
    {
        $catalog = AcfCloneFixtures::pageCatalog();
        $maps    = $catalog->nestedResolutionMaps('page', $catalog->fieldTypesForPostType('page'));
        $aId     = AcfCloneFixtures::cloneATitlePosted();

        $this->assertSame(array(AcfCloneFixtures::CLONE_A, $aId), $maps['paths'][$aId]);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $maps['clone_keys'][$aId]);
        $this->assertSame(AcfCloneFixtures::REPEATER, $maps['repeater_keys'][FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_REP, AcfCloneFixtures::TITLE)]);
        $this->assertSame(AcfCloneFixtures::FLEX, $maps['flex_keys'][FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_FLEX, AcfCloneFixtures::TITLE)]);
        $this->assertArrayNotHasKey(AcfCloneFixtures::TITLE, $maps['clone_keys']);
    }

    public function testCatalogArrayExposesCloneMetadataOnlyForClonedFields(): void
    {
        $catalog = AcfCloneFixtures::pageCatalog();
        $arrays  = array();
        foreach ($catalog->fieldsForPostType('page') as $field) {
            $arrays[$field->resolutionId()] = $field->toCatalogArray();
        }

        $this->assertArrayNotHasKey('clone', $arrays[AcfCloneFixtures::TITLE]);
        $this->assertArrayNotHasKey('resolution_id', $arrays[AcfCloneFixtures::TITLE]);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $arrays[AcfCloneFixtures::cloneATitlePosted()]['clone']);
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $arrays[AcfCloneFixtures::cloneATitlePosted()]['resolution_id']);
    }

    public function testEmptyCloneContainerIsNotASelectableField(): void
    {
        $catalog = new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'   => 'field_clone_empty',
                        'name'  => 'empty',
                        'label' => 'Empty',
                        'type'  => 'clone',
                    ),
                );
            }
        );

        $this->assertSame(array(), $catalog->fieldsForPostType('page'));
    }
}
