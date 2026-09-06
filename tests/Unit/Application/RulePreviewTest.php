<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\RulePreview;
use PHPUnit\Framework\TestCase;

final class RulePreviewTest extends TestCase
{
    public function testQuotedTrueFalseWhenAndRequiredThen(): void
    {
        $this->assertSame(
            'When Show "New" Tag is Yes, PowerReviews Page ID is required.',
            RulePreview::fromEditor(
                array(
                    array(
                        'field_key' => 'field_683097e0dc6d2',
                        'operator'  => 'equals',
                        'operand'   => '1',
                    ),
                ),
                array(
                    array(
                        'field_key' => 'field_6a05f9e8420ea',
                        'type'      => 'required',
                    ),
                ),
                $this->fieldMeta()
            )
        );
    }

    public function testNoWhenUsesThenOnly(): void
    {
        $this->assertSame(
            'PowerReviews Page ID is required.',
            RulePreview::fromEditor(
                array(),
                array(
                    array(
                        'field_key' => 'field_6a05f9e8420ea',
                        'type'      => 'required',
                    ),
                ),
                $this->fieldMeta()
            )
        );
    }

    public function testIncompleteStates(): void
    {
        $this->assertSame(
            RulePreview::needThenMessage(),
            RulePreview::fromEditor(array(), array(), $this->fieldMeta())
        );
        $this->assertSame(
            RulePreview::incompleteWhenMessage(),
            RulePreview::fromEditor(
                array(
                    array(
                        'field_key' => 'field_683097e0dc6d2',
                        'operator'  => 'equals',
                        'operand'   => '',
                    ),
                ),
                array(
                    array(
                        'field_key' => 'field_6a05f9e8420ea',
                        'type'      => 'required',
                    ),
                ),
                $this->fieldMeta()
            )
        );
        $this->assertSame(
            RulePreview::incompleteThenMessage(),
            RulePreview::fromEditor(
                array(),
                array(
                    array(
                        'field_key' => 'field_6a05f9e8420ea',
                        'type'      => 'min_length',
                        'min'       => '',
                    ),
                ),
                $this->fieldMeta()
            )
        );
    }

    public function testYesNoAndLengthPhrases(): void
    {
        $this->assertSame(
            'When Show "New" Tag is No, PowerReviews Page ID must be at least 50 characters.',
            RulePreview::fromEditor(
                array(
                    array(
                        'field_key' => 'field_683097e0dc6d2',
                        'operator'  => 'equals',
                        'operand'   => '0',
                    ),
                ),
                array(
                    array(
                        'field_key' => 'field_6a05f9e8420ea',
                        'type'      => 'min_length',
                        'min'       => '50',
                    ),
                ),
                $this->fieldMeta()
            )
        );
        $this->assertSame(
            'When Cook Time is greater than 30, Ingredients is required.',
            RulePreview::fromEditor(
                array(
                    array(
                        'field_key' => 'field_cook_time',
                        'operator'  => 'greater_than',
                        'operand'   => '30',
                    ),
                ),
                array(
                    array(
                        'field_key' => 'field_ingredients',
                        'type'      => 'required',
                    ),
                ),
                $this->fieldMeta()
            )
        );
        $this->assertSame(
            'When Total Time is at least 60, Ingredients is required.',
            RulePreview::fromEditor(
                array(
                    array(
                        'field_key' => 'field_total_time',
                        'operator'  => 'greater_than_or_equal',
                        'operand'   => '60',
                    ),
                ),
                array(
                    array(
                        'field_key' => 'field_ingredients',
                        'type'      => 'required',
                    ),
                ),
                $this->fieldMeta()
            )
        );
        $this->assertSame(
            'When Show "New" Tag is not empty, PowerReviews Page ID must be at most 100 characters.',
            RulePreview::fromEditor(
                array(
                    array(
                        'field_key' => 'field_683097e0dc6d2',
                        'operator'  => 'is_not_empty',
                    ),
                ),
                array(
                    array(
                        'field_key' => 'field_6a05f9e8420ea',
                        'type'      => 'max_length',
                        'max'       => '100',
                    ),
                ),
                $this->fieldMeta()
            )
        );
    }

    /**
     * @return array<string, array{label: string, type: string}>
     */
    private function fieldMeta(): array
    {
        return array(
            'field_683097e0dc6d2' => array('label' => 'Show "New" Tag', 'type' => 'true_false'),
            'field_6a05f9e8420ea' => array('label' => 'PowerReviews Page ID', 'type' => 'text'),
            'field_cook_time'     => array('label' => 'Cook Time', 'type' => 'number'),
            'field_total_time'    => array('label' => 'Total Time', 'type' => 'number'),
            'field_ingredients'   => array('label' => 'Ingredients', 'type' => 'textarea'),
        );
    }
}
