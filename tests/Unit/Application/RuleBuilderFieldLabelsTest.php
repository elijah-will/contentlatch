<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\RuleBuilderFieldLabels;
use PHPUnit\Framework\TestCase;

final class RuleBuilderFieldLabelsTest extends TestCase
{
    public function testGroupedOptionsUseLeafLabelsAndKeepBreadcrumbForPreview(): void
    {
        $field = array(
            'key'         => 'field_direction',
            'label'       => 'Direction',
            'breadcrumb'  => 'Directions → Section Directions → Direction',
            'group_label' => 'Recipes → Directions → Section Directions',
            'container'   => 'repeater',
        );

        $this->assertSame('Direction (every row)', RuleBuilderFieldLabels::optionLabel($field));
        $this->assertSame(
            'Directions → Section Directions → Direction (every row)',
            RuleBuilderFieldLabels::previewLabel($field)
        );
        $this->assertSame(
            'Recipes → Directions → Section Directions',
            RuleBuilderFieldLabels::groupLabel($field)
        );
    }

    public function testUngroupedOptionsKeepBreadcrumbOrLeaf(): void
    {
        $this->assertSame(
            'Recipe Description',
            RuleBuilderFieldLabels::optionLabel(array(
                'key'   => 'field_description',
                'label' => 'Recipe Description',
            ))
        );
        $this->assertSame(
            'Title',
            RuleBuilderFieldLabels::optionLabel(array(
                'key'         => 'title',
                'label'       => 'Title',
                'group_label' => 'WordPress',
            ))
        );
    }

    public function testFlexibleOptionsKeepLayoutRowSuffixOnLeaf(): void
    {
        $field = array(
            'key'          => 'field_hero_title',
            'label'        => 'Title',
            'breadcrumb'   => 'Modules → Hero → Title',
            'group_label'  => 'Modules → Hero',
            'container'    => 'flexible_content',
            'layout'       => 'hero',
            'layout_label' => 'Hero',
        );

        $this->assertSame('Title (every Hero row)', RuleBuilderFieldLabels::optionLabel($field));
        $this->assertSame(
            'Modules → Hero → Title (every Hero row)',
            RuleBuilderFieldLabels::previewLabel($field)
        );
    }
}
