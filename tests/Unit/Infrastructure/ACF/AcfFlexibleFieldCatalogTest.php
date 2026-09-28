<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Domain\FieldRef;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Tests\Support\AcfFlexibleFixtures;
use ContentLatch\Tests\Support\AcfRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class AcfFlexibleFieldCatalogTest extends TestCase
{
    public function testFlexibleScalarChildrenAreCataloguedWithLayoutIdentity(): void
    {
        $catalog = AcfFlexibleFixtures::pageCatalog();
        $byKey   = array();
        foreach ($catalog->fieldsForPostType('page') as $field) {
            $byKey[$field->key] = $field;
        }

        $this->assertArrayNotHasKey(AcfFlexibleFixtures::MODULES, $byKey);
        $this->assertArrayHasKey(AcfFlexibleFixtures::HERO_TITLE, $byKey);
        $this->assertArrayHasKey(AcfFlexibleFixtures::HERO_EYEBROW, $byKey);
        $this->assertArrayHasKey(AcfFlexibleFixtures::VIDEO_HERO_TITLE, $byKey);
        $this->assertArrayHasKey(AcfFlexibleFixtures::CTA_TITLE, $byKey);

        $hero = $byKey[AcfFlexibleFixtures::HERO_TITLE];
        $this->assertSame(FieldRef::CONTAINER_FLEXIBLE, $hero->container);
        $this->assertSame('hero', $hero->layout);
        $this->assertSame('layout_66e48d4511343', $hero->layoutKey);
        $this->assertSame('Hero', $hero->layoutLabel);
        $this->assertSame(array(AcfFlexibleFixtures::MODULES, AcfFlexibleFixtures::HERO_TITLE), $hero->path);
        $this->assertSame('Modules → Hero → Title', $hero->breadcrumb());
        $this->assertSame('Modules → Hero', $hero->groupLabel());
        $this->assertSame('hero', $hero->toCatalogArray()['layout']);
        $this->assertSame('layout_66e48d4511343', $hero->toCatalogArray()['layout_key']);
        $this->assertSame('Hero', $hero->toCatalogArray()['layout_label']);
        $this->assertSame('Modules → Hero → Title', $hero->toFieldRef()->label);
        $this->assertSame('hero', $hero->toFieldRef()->layout);
    }

    public function testSimilarLabelsAcrossLayoutsRemainDistinct(): void
    {
        $catalog = AcfFlexibleFixtures::pageCatalog();
        $titles  = array();
        foreach ($catalog->fieldsForPostType('page') as $field) {
            if ($field->label === 'Title' || $field->name === 'title') {
                $titles[$field->key] = $field->layout;
            }
        }

        $this->assertSame('hero', $titles[AcfFlexibleFixtures::HERO_TITLE]);
        $this->assertSame('cta', $titles[AcfFlexibleFixtures::CTA_TITLE]);
        $this->assertSame('video_hero', $titles[AcfFlexibleFixtures::VIDEO_HERO_TITLE]);
        $this->assertCount(3, $titles);
    }

    public function testFlexibleGroupScalarIsCatalogued(): void
    {
        $catalog = AcfFlexibleFixtures::pageCatalog();
        $byKey   = array();
        foreach ($catalog->fieldsForPostType('page') as $field) {
            $byKey[$field->key] = $field;
        }

        $this->assertArrayHasKey(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE, $byKey);
        $headline = $byKey[AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE];
        $this->assertSame(FieldRef::CONTAINER_FLEXIBLE, $headline->container);
        $this->assertSame('content_block', $headline->layout);
        $this->assertSame(
            array(
                AcfFlexibleFixtures::MODULES,
                AcfFlexibleFixtures::CONTENT_BLOCK_1,
                AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE,
            ),
            $headline->path
        );
        $this->assertSame('Modules → Content Block → Content Block 1 → Headline', $headline->breadcrumb());
    }

    public function testUnsupportedFlexibleStructuresAreExcluded(): void
    {
        $keys = array_map(
            static fn ($field): string => $field->key,
            AcfFlexibleFixtures::pageCatalog()->fieldsForPostType('page')
        );

        $this->assertNotContains(AcfFlexibleFixtures::ACCORDION_ITEMS, $keys);
        $this->assertNotContains(AcfFlexibleFixtures::ACCORDION_TITLE, $keys);
        $this->assertNotContains('field_66e48da011348', $keys);
        $this->assertNotContains('field_inner_flex', $keys);
        $this->assertNotContains('field_inner_title', $keys);
        $this->assertNotContains('field_cloned', $keys);
    }

    public function testGroupAndRepeaterCatalogBehaviorIsUnchanged(): void
    {
        $product = AcfRepeaterFixtures::productCatalog()->fieldsForPostType('product');
        $recipe  = AcfRepeaterFixtures::recipeCatalog()->fieldsForPostType('recipe');

        $this->assertSame(AcfRepeaterFixtures::PRODUCT_SIZE, $product[1]->key);
        $this->assertSame('repeater', $product[1]->container);
        $this->assertSame('', $product[1]->layout);
        $this->assertSame(AcfRepeaterFixtures::INGREDIENT, $recipe[0]->key);
        $this->assertSame('repeater', $recipe[0]->container);

        $group = new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'        => 'field_group',
                        'name'       => 'meta',
                        'label'      => 'Meta',
                        'type'       => 'group',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_group_title',
                                'name'  => 'title',
                                'label' => 'Title',
                                'type'  => 'text',
                            ),
                        ),
                    ),
                );
            }
        );
        $child = $group->fieldsForPostType('product')[0];
        $this->assertSame('group', $child->container);
        $this->assertSame('Meta → Title', $child->breadcrumb());
    }

    public function testNestedResolutionMapsIncludeFlexKeysAndLayouts(): void
    {
        $catalog = AcfFlexibleFixtures::pageCatalog();
        $maps    = $catalog->nestedResolutionMaps('page', $catalog->fieldTypesForPostType('page'));

        $this->assertSame(AcfFlexibleFixtures::MODULES, $maps['flex_keys'][AcfFlexibleFixtures::HERO_TITLE]);
        $this->assertSame('hero', $maps['layouts'][AcfFlexibleFixtures::HERO_TITLE]);
        $this->assertSame(AcfFlexibleFixtures::MODULES, $maps['flex_keys'][AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE]);
        $this->assertSame('content_block', $maps['layouts'][AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE]);
        $this->assertArrayNotHasKey(AcfFlexibleFixtures::HERO_TITLE, $maps['repeater_keys']);
    }

    public function testUnnamedLayoutsRemainExcluded(): void
    {
        $catalog = new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'     => 'field_flex',
                        'name'    => 'layout',
                        'label'   => 'Layout',
                        'type'    => 'flexible_content',
                        'layouts' => array(
                            array(
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_flex_title',
                                        'name'  => 'title',
                                        'label' => 'Title',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                        ),
                    ),
                );
            }
        );

        $this->assertSame(array(), $catalog->fieldsForPostType('page'));
    }
}
