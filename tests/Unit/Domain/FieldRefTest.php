<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain;

use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\FieldRef;
use PHPUnit\Framework\TestCase;

final class FieldRefTest extends TestCase
{
    public function testTopLevelArrayOmitsOptionalMetadata(): void
    {
        $ref = new FieldRef('field_recipe_description', 'recipe_description', 'Recipe Description');

        $this->assertSame(
            array(
                'key'   => 'field_recipe_description',
                'name'  => 'recipe_description',
                'label' => 'Recipe Description',
            ),
            $ref->toArray()
        );
        $this->assertFalse($ref->isNested());

        $loaded = FieldRef::fromArray($ref->toArray());
        $this->assertSame($ref->key, $loaded->key);
        $this->assertSame(array(), $loaded->path);
        $this->assertSame('', $loaded->container);
    }

    public function testNestedFieldRefSerializesPathAndContainer(): void
    {
        $ref = new FieldRef(
            'field_ingredients',
            'ingredients',
            'Product Details → Ingredients',
            array('field_product_details', 'field_ingredients'),
            'group'
        );

        $this->assertSame(
            array(
                'key'       => 'field_ingredients',
                'name'      => 'ingredients',
                'label'     => 'Product Details → Ingredients',
                'path'      => array('field_product_details', 'field_ingredients'),
                'container' => 'group',
            ),
            $ref->toArray()
        );

        $loaded = FieldRef::fromArray($ref->toArray());
        $this->assertSame($ref->path, $loaded->path);
        $this->assertSame('group', $loaded->container);
        $this->assertTrue($loaded->isNested());
    }

    public function testRepeaterChildSerializesPathAndContainer(): void
    {
        $ref = new FieldRef(
            'field_product_size',
            'product_size',
            'Product Information → Item Size → Product Size',
            array('field_product_information', 'field_item_size', 'field_product_size'),
            'repeater'
        );

        $this->assertTrue($ref->isRepeaterChild());
        $this->assertSame('repeater', $ref->toArray()['container']);

        $loaded = FieldRef::fromArray($ref->toArray());
        $this->assertSame($ref->path, $loaded->path);
        $this->assertSame('repeater', $loaded->container);
        $this->assertTrue($loaded->isRepeaterChild());
    }

    public function testFlexibleChildSerializesLayoutAndKeepsFieldKeyPath(): void
    {
        $ref = new FieldRef(
            'field_66e48d6611345',
            'title',
            'Modules → Hero → Title',
            array('field_660d684429de1', 'field_66e48d6611345'),
            FieldRef::CONTAINER_FLEXIBLE,
            'hero'
        );

        $this->assertTrue($ref->isFlexibleChild());
        $this->assertFalse($ref->isRepeaterChild());
        $this->assertSame(
            array(
                'key'       => 'field_66e48d6611345',
                'name'      => 'title',
                'label'     => 'Modules → Hero → Title',
                'path'      => array('field_660d684429de1', 'field_66e48d6611345'),
                'container' => 'flexible_content',
                'layout'    => 'hero',
            ),
            $ref->toArray()
        );

        $loaded = FieldRef::fromArray($ref->toArray());
        $this->assertSame('hero', $loaded->layout);
        $this->assertSame('flexible_content', $loaded->container);
        $this->assertSame($ref->path, $loaded->path);
    }

    public function testExistingGroupDocumentsDoNotGainLayout(): void
    {
        $ref = new FieldRef(
            'field_ingredients',
            'ingredients',
            'Product Details → Ingredients',
            array('field_product_details', 'field_ingredients'),
            'group'
        );

        $this->assertArrayNotHasKey('layout', $ref->toArray());
        $this->assertSame('', $ref->layout);
    }

    public function testLayoutIsRejectedOnNonFlexibleContainers(): void
    {
        $this->expectException(InvalidRuleException::class);
        new FieldRef(
            'field_ingredients',
            'ingredients',
            'Ingredients',
            array('field_product_details', 'field_ingredients'),
            'group',
            'hero'
        );
    }

    public function testFlexibleChildRequiresASafeLayoutName(): void
    {
        $this->expectException(InvalidRuleException::class);
        new FieldRef(
            'field_66e48d6611345',
            'title',
            'Title',
            array('field_660d684429de1', 'field_66e48d6611345'),
            FieldRef::CONTAINER_FLEXIBLE,
            ''
        );
    }

    public function testLayoutKeyCannotBeStoredInPath(): void
    {
        $this->expectException(InvalidRuleException::class);
        new FieldRef(
            'field_66e48d6611345',
            'title',
            'Title',
            array('field_660d684429de1', 'layout_66e48d4511343', 'field_66e48d6611345'),
            FieldRef::CONTAINER_FLEXIBLE,
            'hero'
        );
    }

    public function testCloneFieldSerializesCloneAndOmitsItForOrdinaryFields(): void
    {
        $clone = new FieldRef(
            'field_title',
            'shared_a_title',
            'Shared Content → Title',
            array('field_clone_a', 'field_clone_a_field_title'),
            FieldRef::CONTAINER_CLONE,
            '',
            'field_clone_a'
        );

        $this->assertSame('field_clone_a_field_title', $clone->resolutionId());
        $this->assertTrue($clone->isCloneChild());
        $this->assertSame(
            array(
                'key'       => 'field_title',
                'name'      => 'shared_a_title',
                'label'     => 'Shared Content → Title',
                'path'      => array('field_clone_a', 'field_clone_a_field_title'),
                'container' => 'clone',
                'clone'     => 'field_clone_a',
            ),
            $clone->toArray()
        );

        $loaded = FieldRef::fromArray($clone->toArray());
        $this->assertSame('field_title', $loaded->key);
        $this->assertSame('field_clone_a', $loaded->clone);
        $this->assertSame('field_clone_a_field_title', $loaded->resolutionId());

        $ordinary = new FieldRef('field_title', 'title', 'Title');
        $this->assertArrayNotHasKey('clone', $ordinary->toArray());
        $this->assertSame('field_title', $ordinary->resolutionId());
    }

    public function testUnknownDocumentKeysIncludingIntegrationAreIgnored(): void
    {
        $loaded = FieldRef::fromArray(array(
            'key'          => 'field_title',
            'name'         => 'title',
            'label'        => 'Title',
            'integration'  => 'acf',
            'resolution_id'=> 'should-not-become-the-key',
        ));

        $this->assertSame('field_title', $loaded->key);
        $this->assertSame('field_title', $loaded->resolutionId());
        $this->assertArrayNotHasKey('integration', $loaded->toArray());
        $this->assertSame(
            array(
                'key'   => 'field_title',
                'name'  => 'title',
                'label' => 'Title',
            ),
            $loaded->toArray()
        );
    }

    public function testSeamlessCompositePathIsAllowedWhenCloneIsSet(): void
    {
        $ref = FieldRef::fromArray(array(
            'key'       => 'field_title',
            'name'      => 'title',
            'label'     => 'Title',
            'path'      => array('field_clone_a', 'field_clone_a_field_title'),
            'container' => 'clone',
            'clone'     => 'field_clone_a',
        ));

        $this->assertSame('field_title', $ref->key);
        $this->assertSame('field_clone_a_field_title', $ref->path[1]);
    }

    public function testArbitraryClonePathIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        FieldRef::fromArray(array(
            'key'       => 'field_title',
            'name'      => 'title',
            'label'     => 'Title',
            'path'      => array('field_other_clone', 'field_title'),
            'container' => 'clone',
            'clone'     => 'field_clone_a',
        ));
    }

    public function testInvalidPathsAreRejected(): void
    {
        $cases = array(
            array('path' => array('field_only'), 'container' => 'group'),
            array('path' => array('field_group', 'field_other'), 'container' => 'group'),
            array('path' => array('field_group.field_child', 'field_ingredients'), 'container' => 'group'),
            array('path' => array('field_group', 'field_ingredients'), 'container' => 'clone'),
            array('path' => array(), 'container' => 'group'),
            array('path' => array('field_group' => 'field_ingredients'), 'container' => 'group'),
        );

        foreach ($cases as $data) {
            try {
                FieldRef::fromArray(
                    array(
                        'key'       => 'field_ingredients',
                        'name'      => 'ingredients',
                        'label'     => 'Ingredients',
                        'path'      => $data['path'],
                        'container' => $data['container'],
                    )
                );
                $this->fail('Expected InvalidRuleException for ' . json_encode($data));
            } catch (InvalidRuleException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }
}
