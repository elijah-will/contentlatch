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

    public function testInvalidPathsAreRejected(): void
    {
        $cases = array(
            array('path' => array('field_only'), 'container' => 'group'),
            array('path' => array('field_group', 'field_other'), 'container' => 'group'),
            array('path' => array('field_group.field_child', 'field_ingredients'), 'container' => 'group'),
            array('path' => array('field_group', 'field_ingredients'), 'container' => 'repeater'),
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
