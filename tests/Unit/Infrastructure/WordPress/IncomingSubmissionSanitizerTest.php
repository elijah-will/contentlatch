<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

require_once dirname(__DIR__, 3) . '/Support/wordpress-admin-functions.php';

use ContentLatch\Infrastructure\ACF\FieldDefinition;
use ContentLatch\Infrastructure\WordPress\IncomingSubmissionSanitizer;
use PHPUnit\Framework\TestCase;
use stdClass;

final class IncomingSubmissionSanitizerTest extends TestCase
{
    public function testAcfTreePreservesNestedRepeaterIndexesAndTypes(): void
    {
        $types = array(
            'field_note'   => 'textarea',
            'field_body'   => 'wysiwyg',
            'field_flag'   => 'true_false',
            'field_amount' => 'number',
            'field_email'  => 'email',
            'field_link'   => 'url',
            'field_label'  => 'text',
        );

        $tree = IncomingSubmissionSanitizer::acfTree(
            array(
                'field_rows' => array(
                    0 => array(
                        'field_note'   => "line1\nline2",
                        'field_body'   => '<p>Safe</p><script>alert(1)</script>',
                        'field_flag'   => '1',
                        'field_amount' => '12.5',
                        'field_email'  => 'a@example.com',
                        'field_link'   => 'https://example.com/x',
                        'field_label'  => 'O\'Brien «Nuevo» タグ',
                    ),
                    2 => array(
                        'field_note' => "kept\nindex",
                    ),
                ),
                'field_label' => 'top',
            ),
            $types
        );

        $this->assertSame(array(0, 2), array_keys($tree['field_rows']));
        $this->assertSame("line1\nline2", $tree['field_rows'][0]['field_note']);
        $this->assertStringContainsString('<p>Safe</p>', $tree['field_rows'][0]['field_body']);
        $this->assertStringNotContainsString('<script>', $tree['field_rows'][0]['field_body']);
        $this->assertSame('1', $tree['field_rows'][0]['field_flag']);
        $this->assertSame('12.5', $tree['field_rows'][0]['field_amount']);
        $this->assertSame('a@example.com', $tree['field_rows'][0]['field_email']);
        $this->assertSame('https://example.com/x', $tree['field_rows'][0]['field_link']);
        $this->assertSame('O\'Brien «Nuevo» タグ', $tree['field_rows'][0]['field_label']);
        $this->assertSame("kept\nindex", $tree['field_rows'][2]['field_note']);
    }

    public function testAcfTreeDropsObjectsAndPreservesBoolNumericLeaves(): void
    {
        $tree = IncomingSubmissionSanitizer::acfTree(
            array(
                'field_flag'   => true,
                'field_amount' => 3,
                'field_bad'    => new stdClass(),
                'field_map'    => array(
                    'field_flag' => false,
                ),
            ),
            array(
                'field_flag'   => 'true_false',
                'field_amount' => 'number',
            )
        );

        $this->assertTrue($tree['field_flag']);
        $this->assertSame(3, $tree['field_amount']);
        $this->assertArrayNotHasKey('field_bad', $tree);
        $this->assertFalse($tree['field_map']['field_flag']);
    }

    public function testAcfTreeUnknownStringLeavesUseKses(): void
    {
        $tree = IncomingSubmissionSanitizer::acfTree(
            array(
                'field_mystery' => 'Hello<script>x</script>',
            ),
            array()
        );

        $this->assertSame('Hello', $tree['field_mystery']);
    }

    public function testAcfFieldTypesIndexesKeysAndPathLeaves(): void
    {
        $field = new FieldDefinition(
            'field_child',
            'child',
            'Child',
            'text',
            array(),
            array('field_rep', 'field_child'),
            'repeater',
            array('rep', 'child'),
            array('Rep', 'Child'),
            'field_rep',
            '',
            '',
            '',
            'field_clone'
        );

        $types = IncomingSubmissionSanitizer::acfFieldTypes(array($field));
        $this->assertSame('text', $types['field_child']);
        $this->assertSame('text', $types['field_clone_field_child']);
        $this->assertSame('text', $types['field_child']);
    }

    public function testCoreFieldsUseWordPressAppropriateSanitizers(): void
    {
        $this->assertSame('Hello', IncomingSubmissionSanitizer::coreField('post_title', 'Hello<script>'));
        $this->assertStringContainsString(
            '<p>Hi</p>',
            (string) IncomingSubmissionSanitizer::coreField('post_content', '<p>Hi</p><script>x</script>')
        );
        $this->assertStringNotContainsString(
            '<script>',
            (string) IncomingSubmissionSanitizer::coreField('post_content', '<p>Hi</p><script>x</script>')
        );
        $this->assertSame("a\nb", IncomingSubmissionSanitizer::coreField('post_excerpt', "a\nb<script>"));
        $this->assertSame('my-slug', IncomingSubmissionSanitizer::coreField('post_name', 'My Slug!'));
        $this->assertSame(12, IncomingSubmissionSanitizer::coreField('post_author', '12'));
        $this->assertSame(44, IncomingSubmissionSanitizer::coreField('_thumbnail_id', '44'));
        $this->assertSame(-1, IncomingSubmissionSanitizer::coreField('_thumbnail_id', '-1'));
        $this->assertSame(-1, IncomingSubmissionSanitizer::coreField('featured_image', -1));
        $this->assertSame(0, IncomingSubmissionSanitizer::coreField('_thumbnail_id', '0'));
    }

    public function testNonArrayAcfTreeBecomesEmptyArray(): void
    {
        $this->assertSame(array(), IncomingSubmissionSanitizer::acfTree('nope'));
        $this->assertSame(array(), IncomingSubmissionSanitizer::acfTree(null));
    }
}
