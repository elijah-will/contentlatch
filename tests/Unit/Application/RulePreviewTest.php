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
        );
    }
}
