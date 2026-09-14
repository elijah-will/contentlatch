<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\EditorFieldNavigation;
use PHPUnit\Framework\TestCase;

final class EditorFieldNavigationTest extends TestCase
{
    public function testSafeFieldKeysAreAppendedToEditUrls(): void
    {
        $url = EditorFieldNavigation::appendToEditUrl(
            'http://example.test/wp-admin/post.php?post=42&action=edit',
            'field_64f8a42a61f56'
        );

        $this->assertTrue(EditorFieldNavigation::isSafeLayoutName('hero'));
        $this->assertTrue(EditorFieldNavigation::isSafeLayoutName('content_block'));
        $this->assertFalse(EditorFieldNavigation::isSafeLayoutName('hero layout'));
        $this->assertTrue(EditorFieldNavigation::isSafeFieldKey('field_64f8a42a61f56'));
        $this->assertTrue(EditorFieldNavigation::isSafeFieldKey('field_ingredients'));
        $this->assertTrue(EditorFieldNavigation::isSafeFieldKey('field_clone_a_field_title'));
        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_field=field_clone_a_field_title',
            EditorFieldNavigation::appendToEditUrl(
                'http://example.test/wp-admin/post.php?post=42&action=edit',
                'field_clone_a_field_title'
            )
        );
        $this->assertFalse(EditorFieldNavigation::isSafeFieldKey('field_product_details.field_ingredients'));
        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_field=field_ingredients',
            EditorFieldNavigation::appendToEditUrl(
                'http://example.test/wp-admin/post.php?post=42&action=edit',
                'field_ingredients'
            )
        );
        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit',
            EditorFieldNavigation::appendToEditUrl(
                'http://example.test/wp-admin/post.php?post=42&action=edit',
                'field_product_details.field_ingredients'
            )
        );
        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_field=field_64f8a42a61f56',
            $url
        );
    }

    public function testUnsafeOrEmptyTargetsFallBackToTheOriginalEditUrl(): void
    {
        $edit = 'http://example.test/wp-admin/post.php?post=42&action=edit';

        $this->assertFalse(EditorFieldNavigation::isSafeFieldKey('field_nested/path'));
        $this->assertFalse(EditorFieldNavigation::isSafeFieldKey('description'));
        $this->assertFalse(EditorFieldNavigation::isSafeFieldKey('field_64f8a42a61f56"><script>'));
        $this->assertSame($edit, EditorFieldNavigation::appendToEditUrl($edit, 'not-a-field'));
        $this->assertSame('', EditorFieldNavigation::appendToEditUrl('', 'field_64f8a42a61f56'));
    }

    public function testRequestedFieldKeyReadsOnlySafeQueryValues(): void
    {
        $this->assertSame(
            'field_64f8a42a61f56',
            EditorFieldNavigation::requestedFieldKey(array(
                'contentguard_field' => 'field_64f8a42a61f56',
            ))
        );
        $this->assertSame('', EditorFieldNavigation::requestedFieldKey(array(
            'contentguard_field' => 'field_64f8a42a61f56/../x',
        )));
        $this->assertSame('', EditorFieldNavigation::requestedFieldKey(array()));
        $this->assertSame(
            'field_650071058895b',
            EditorFieldNavigation::requestedFieldKey(array(
                'contentguard_field' => 'field_650071058895b',
                'contentguard_run'   => '7',
            ))
        );
    }

    public function testAuditAdminUrlsAreNeverUsedAsEditDestinations(): void
    {
        $audit = 'http://example.test/wp-admin/admin.php?page=contentguard-audit&post_type=recipe';

        $this->assertTrue(EditorFieldNavigation::looksLikeAuditAdminUrl($audit));
        $this->assertSame('', EditorFieldNavigation::normalizeEditorUrl($audit));
        $this->assertSame('', EditorFieldNavigation::appendToEditUrl($audit, 'field_64f8a42a61f56'));
    }

    public function testNavigableFieldKeyRejectsUnsafeValues(): void
    {
        $this->assertSame('field_abc123', EditorFieldNavigation::navigableFieldKey('field_abc123'));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey(''));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey(null));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey('field_nested/path'));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey('acf[field_abc123]'));
        $this->assertSame('Go to field: Recipe Description', EditorFieldNavigation::goToFieldAria('Recipe Description'));
        $this->assertSame('Go to field: field', EditorFieldNavigation::goToFieldAria(''));
    }

    public function testAuditRunContextIsAppendedWithoutMessagesOrPostType(): void
    {
        $url = EditorFieldNavigation::appendToEditUrl(
            'http://example.test/wp-admin/post.php?post=42&action=edit',
            'field_64f8a42a61f56',
            7
        );

        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_field=field_64f8a42a61f56&contentguard_run=7',
            $url
        );
        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_run=7',
            EditorFieldNavigation::appendToEditUrl(
                'http://example.test/wp-admin/post.php?post=42&action=edit',
                'not-a-field',
                7
            )
        );
        $this->assertStringNotContainsString('post_type=', $url);
        $this->assertStringNotContainsString('page=contentguard-audit', $url);
    }

    public function testRequestedRunIdRejectsTamperedValues(): void
    {
        $this->assertSame(7, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '7',
        )));
        $this->assertSame(7, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => 7,
        )));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array()));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '0',
        )));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '-3',
        )));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '7abc',
        )));
        $this->assertSame(0, EditorFieldNavigation::requestedRunId(array(
            'contentguard_run' => '7"><script>',
        )));
        $this->assertSame(0, EditorFieldNavigation::sanitizeRunId('Description is required'));
    }

    public function testFlexibleRowTargetsStayTransientAndNeverEnterUrls(): void
    {
        $this->assertSame(array(3), EditorFieldNavigation::displayRowsFromContext(array(
            'layout'      => 'hero',
            'display_row' => 3,
        )));
        $this->assertSame(
            array(3),
            EditorFieldNavigation::sanitizeDisplayRows(array('3', 3, 0, -1, 'row-3'))
        );
        $this->assertSame(array(), EditorFieldNavigation::displayRowsFromContext(array(
            'display_row' => 3,
        )));
        $this->assertSame(array(1, 3), EditorFieldNavigation::flexDisplayRowsFromSnapshot(
            'Title is required in 2 Hero rows (rows 1, 3).'
        ));
        $this->assertSame(array(3), EditorFieldNavigation::flexDisplayRowsFromSnapshot(
            'Title is required in Hero row 3.'
        ));
        $this->assertSame(array(), EditorFieldNavigation::flexDisplayRowsFromSnapshot(
            'Ingredient is required in 3 rows (rows 1, 3, 5).'
        ));
        $this->assertSame(array(), EditorFieldNavigation::flexDisplayRowsFromSnapshot(
            'Name is required in 3 rows (rows 1/1, 1/2, 2/1).'
        ));
        $this->assertSame(
            'Title is required in 2 Hero rows.',
            EditorFieldNavigation::snapshotMessageWithoutRows('Title is required in 2 Hero rows (rows 1, 3).')
        );
        $this->assertSame('hero', EditorFieldNavigation::layoutFromContext(array('layout' => 'hero')));
        $this->assertSame('', EditorFieldNavigation::layoutFromContext(array('layout' => 'hero layout')));
        $this->assertSame(
            'Go to Modules → Hero → Title, row 3',
            EditorFieldNavigation::goToLayoutRowAria('Modules → Hero → Title', 3)
        );

        $url = EditorFieldNavigation::appendToEditUrl(
            'http://example.test/wp-admin/post.php?post=42&action=edit',
            'field_66e48d6611345',
            7
        );
        $this->assertSame(
            'http://example.test/wp-admin/post.php?post=42&action=edit&contentguard_field=field_66e48d6611345&contentguard_run=7',
            $url
        );
        $this->assertStringNotContainsString('contentguard_row', $url);
        $this->assertStringNotContainsString('row-2', $url);
        $this->assertStringContainsString('data-contentguard-display-row="3"', EditorFieldNavigation::fieldTriggerAttributes(
            'field_66e48d6611345',
            'hero',
            3
        ));
        $this->assertStringContainsString('Row 1', EditorFieldNavigation::rowButtonsHtml(
            'field_66e48d6611345',
            'Modules → Hero → Title',
            'hero',
            array(1, 3)
        ));
        $this->assertSame('', EditorFieldNavigation::rowButtonsHtml(
            'field_66e48d6611345',
            'Modules → Hero → Title',
            'hero',
            array(3)
        ));
    }

    public function testOneLevelRepeaterPathUsesInputNameAndLeavesUrlsUntouched(): void
    {
        $this->assertSame(
            'field_65007238dd468',
            EditorFieldNavigation::repeaterKeyFromInputName(
                'acf[field_65007238dd468][row-1][field_65011ffeae1ce]'
            )
        );
        $this->assertSame(
            'field_65138ec34ed67',
            EditorFieldNavigation::repeaterKeyFromInputName(
                'acf[field_650070df8895a][row-0][field_65138ec34ed67][row-13][field_650071058895b]'
            )
        );
        $this->assertSame('', EditorFieldNavigation::repeaterKeyFromInputName('acf[field_description]'));
        $this->assertSame('', EditorFieldNavigation::repeaterKeyFromInputName('not-an-input'));

        $item = EditorFieldNavigation::withEvaluationRowTargets(
            array('fieldKey' => 'field_65011ffeae1ce'),
            array(
                'display_row' => 2,
                'input_name'  => 'acf[field_65007238dd468][row-1][field_65011ffeae1ce]',
            )
        );
        $this->assertSame(
            array(
                array(
                    'repeater'    => 'field_65007238dd468',
                    'display_row' => 2,
                ),
            ),
            $item['repeaterPath']
        );
        $this->assertArrayNotHasKey('affectedRows', $item);
        $this->assertArrayNotHasKey('layout', $item);

        $attrs = EditorFieldNavigation::fieldTriggerAttributes(
            'field_65011ffeae1ce',
            '',
            0,
            $item['repeaterPath']
        );
        $this->assertStringContainsString('data-contentguard-field="field_65011ffeae1ce"', $attrs);
        $this->assertStringContainsString('data-contentguard-repeater-path=', $attrs);
        $this->assertStringContainsString('field_65007238dd468', $attrs);
        $this->assertStringNotContainsString('data-contentguard-display-row', $attrs);
        $this->assertStringNotContainsString('contentguard_row', EditorFieldNavigation::appendToEditUrl(
            'http://example.test/wp-admin/post.php?post=42&action=edit',
            'field_65011ffeae1ce'
        ));
    }

    public function testNestedRepeaterPathUsesStructuredCoordinatesOnly(): void
    {
        $chain = array(
            array(
                'repeater'    => 'field_650070df8895a',
                'key'         => 'row-0',
                'index'       => 0,
                'display_row' => 1,
            ),
            array(
                'repeater'    => 'field_65138ec34ed67',
                'key'         => 'row-13',
                'index'       => 13,
                'display_row' => 14,
            ),
        );
        $item = EditorFieldNavigation::withEvaluationRowTargets(
            array('fieldKey' => 'field_650071058895b'),
            array('repeater_rows' => $chain)
        );

        $this->assertSame(
            array(
                array('repeater' => 'field_650070df8895a', 'display_row' => 1),
                array('repeater' => 'field_65138ec34ed67', 'display_row' => 14),
            ),
            $item['repeaterPath']
        );

        $html = EditorFieldNavigation::clickableIssueHtml(
            'field_650071058895b',
            'Ingredients → Section Ingredients → Ingredient',
            'Ingredient is required in row 1/14.',
            'This field is required.',
            'classic',
            '',
            array(),
            $item['repeaterPath']
        );
        $this->assertStringContainsString('data-contentguard-repeater-path=', $html);
        $this->assertStringContainsString('field_650070df8895a', $html);
        $this->assertStringContainsString('field_65138ec34ed67', $html);
        $this->assertStringContainsString('Ingredient is required in row 1/14.', $html);
        $this->assertStringNotContainsString('data-contentguard-display-row', $html);
        $this->assertSame(array(), EditorFieldNavigation::sanitizeRepeaterPath(array(
            array('repeater' => 'not-a-field', 'display_row' => 1),
            array('repeater' => 'field_steps', 'display_row' => 14),
        )));
        $this->assertSame(array(), EditorFieldNavigation::sanitizeRepeaterPath(array(
            array('repeater' => 'field_directions', 'display_row' => 0),
        )));
        $this->assertSame(
            'data-contentguard-repeater-path="invalid"',
            EditorFieldNavigation::repeaterPathAttribute(array(), true)
        );
    }

    public function testClickableIssueHtmlKeepsQuotesAndEscapesXss(): void
    {
        $quoted = EditorFieldNavigation::clickableIssueHtml(
            'field_content',
            'Content',
            'Can\'t contain the word "chicken" in row 1/5.',
            'This field is required.',
            'classic'
        );
        $this->assertStringContainsString('Can&#039;t contain the word &quot;chicken&quot; in row 1/5.', $quoted);
        $this->assertStringNotContainsString('\\\'', $quoted);
        $this->assertStringNotContainsString('\\"', $quoted);

        $xss = EditorFieldNavigation::clickableIssueHtml(
            'field_content',
            'Content',
            'Avoid <script>alert(1)</script> & more',
            'This field is required.',
            'classic'
        );
        $this->assertStringContainsString('Avoid &lt;script&gt;alert(1)&lt;/script&gt; &amp; more', $xss);
        $this->assertStringNotContainsString('<script>', $xss);
        $this->assertStringContainsString('data-contentguard-field="field_content"', $xss);
    }
}
