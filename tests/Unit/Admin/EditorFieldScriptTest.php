<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class EditorFieldScriptTest extends TestCase
{
    public function testEditorFieldScriptTargetsAcfDataKeyAndRespectsReducedMotion(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-field.js');

        $this->assertStringContainsString('.acf-field[data-key="', $js);
        $this->assertStringContainsString('field_[A-Za-z0-9_]+', $js);
        $this->assertStringContainsString('parseResolutionId', $js);
        $this->assertStringContainsString('findClonedField', $js);
        $this->assertStringContainsString('lastIndexOf("_field_")', $js);
        $this->assertStringContainsString('[" + clone + "][" + composite + "]', $js);
        $this->assertStringContainsString('[" + clone + "][" + originalKey + "]', $js);
        $this->assertStringNotContainsString('acfe-modal', $js);
        $this->assertStringContainsString('acf.addAction', $js);
        $this->assertStringContainsString('startFromUrl', $js);
        $this->assertStringContainsString('config.fieldKey', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('aria-live', $js);
        $this->assertStringContainsString('postbox.closed', $js);
        $this->assertStringContainsString('openCollapsedAncestors', $js);
        $this->assertStringContainsString('-collapsed', $js);
        $this->assertStringContainsString('.acf-clone', $js);
        $this->assertStringContainsString('acf-field-repeater', $js);
        $this->assertStringContainsString('collapse-row', $js);
        $this->assertStringContainsString('.layout[data-layout="', $js);
        $this->assertStringContainsString('collapse-layout', $js);
        $this->assertStringContainsString('acf-flexible-content', $js);
        $this->assertStringContainsString('data-contentguard-layout', $js);
        $this->assertStringContainsString('data-contentguard-display-row', $js);
        $this->assertStringContainsString('data-contentguard-repeater-path', $js);
        $this->assertStringContainsString('parseRepeaterPath', $js);
        $this->assertStringContainsString('findFieldInRepeaterPath', $js);
        $this->assertStringContainsString('realRepeaterRows', $js);
        $this->assertStringContainsString('firstRepeaterContainer', $js);
        $this->assertStringContainsString('acf-repeater-values', $js);
        $this->assertStringContainsString('isRealRepeaterRow', $js);
        $this->assertStringContainsString('acf-row', $js);
        $this->assertStringContainsString('raw === "invalid"', $js);
        $this->assertStringContainsString('if (repeaterPath === null)', $js);
        $this->assertStringContainsString('findFieldAtDisplayRow', $js);
        $this->assertStringContainsString('realChildLayouts', $js);
        $this->assertStringContainsString('isRealLayout', $js);
        $this->assertStringContainsString('acf-clone', $js);
        $this->assertStringContainsString('.values', $js);
        $this->assertStringContainsString('displayRow', $js);
        $this->assertStringContainsString('(row "', $js);
        $this->assertStringNotContainsString('contentguard_row', $js);
        $this->assertStringNotContainsString('contentguard_layout=', $js);
        $this->assertStringContainsString('contentguardNavigateToField', $js);
        $this->assertStringContainsString('contentguardNavigateToCore', $js);
        $this->assertStringContainsString('data-contentguard-field', $js);
        $this->assertStringContainsString('data-contentguard-core', $js);
        $this->assertStringContainsString('.editor-post-title__input', $js);
        $this->assertStringContainsString('h1.wp-block-post-title', $js);
        $this->assertStringContainsString('iframe[name="editor-canvas"]', $js);
        $this->assertStringContainsString('contentDocument', $js);
        $this->assertStringContainsString('editorDocuments', $js);
        $this->assertStringContainsString('canvasDocuments', $js);
        $this->assertStringContainsString('queryAllInEditor', $js);
        $this->assertStringContainsString('firstMatchInCanvas', $js);
        $this->assertStringContainsString('.block-editor-writing-flow', $js);
        $this->assertStringContainsString('.is-root-container', $js);
        $this->assertStringContainsString('.editor-styles-wrapper', $js);
        $this->assertStringContainsString('[data-type="core/post-content"]', $js);
        $this->assertStringNotContainsString('.editor-visual-editor', $js);
        $this->assertStringContainsString('post-excerpt', $js);
        $this->assertStringContainsString('featured-image', $js);
        $this->assertStringContainsString('#title', $js);
        $this->assertStringContainsString('#excerpt', $js);
        $this->assertStringContainsString('#postimagediv', $js);
        $this->assertStringContainsString('#edit-slug-box', $js);
        $this->assertStringContainsString('#authordiv', $js);
        $this->assertStringContainsString('openGeneralSidebar', $js);
        $this->assertStringContainsString('toggleEditorPanelOpened', $js);
        $this->assertStringNotContainsString('toggleEditorPanelEnabled', $js);
        $this->assertStringNotContainsString('css-', $js);
        $this->assertStringContainsString('autoNavigate', $js);
        $this->assertStringContainsString('scrollIntoView', $js);
        $this->assertStringNotContainsString('contentguard-field-target', $js);
        $this->assertStringNotContainsString('clearHighlight', $js);
        $this->assertStringNotContainsString('4000', $js);
        $this->assertStringNotContainsString('warnings[0]', $js);
    }

    public function testCoreNavigationDoesNotRetriggerExcerptOrEnableDisabledPanels(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-field.js');
        $blockers = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-rest-blockers.js');
        $warnings = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-warnings.js');

        $this->assertStringContainsString('function excerptControl(', $js);
        $this->assertStringContainsString('function openExcerptDropdownIfClosed(', $js);
        $this->assertStringContainsString('function navigateGutenbergExcerpt(', $js);
        $this->assertStringContainsString('aria-expanded', $js);
        $this->assertStringContainsString('isExcerptDropdownOpen', $js);
        $this->assertMatchesRegularExpression(
            '/function navigateGutenbergExcerpt\([^)]*\)\s*\{(?:(?!\n  function ).)*excerptControl\(\);(?:(?!\n  function ).)*openExcerptDropdownIfClosed\(\);/s',
            $js
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function navigateGutenbergExcerpt\([^)]*\)\s*\{(?:(?!\n  function ).)*clickFirst\(/s',
            $js
        );
        $this->assertSame(1, substr_count($js, 'clickFirst(') - substr_count($js, 'function clickFirst('));
        $this->assertStringNotContainsString('toggleEditorPanelEnabled', $js);
        $this->assertStringContainsString(
            'typeof select.isEditorPanelEnabled === "function" && !select.isEditorPanelEnabled(name)',
            $js
        );
        $this->assertStringContainsString('coreNavGeneration', $js);
        $this->assertStringContainsString('generation !== coreNavGeneration', $js);
        $this->assertStringContainsString('coreNavGeneration += 1', $js);
        $this->assertStringContainsString('tryCoreFocus(fieldId, 20, coreNavGeneration)', $js);
        $this->assertStringContainsString('attemptsLeft <= 0', $js);
        $this->assertStringContainsString('.editor-post-title__input', $js);
        $this->assertStringContainsString('h1.wp-block-post-title', $js);
        $this->assertStringContainsString('iframe[name="editor-canvas"]', $js);
        $this->assertStringContainsString('firstMatchInCanvas', $js);
        $this->assertStringContainsString('.block-editor-writing-flow', $js);
        $this->assertStringNotContainsString('.editor-visual-editor', $js);
        $this->assertStringContainsString('.editor-post-featured-image', $js);
        $this->assertStringContainsString('openEditorPanel("featured-image")', $js);
        $this->assertStringContainsString('openEditorPanel("post-excerpt")', $js);
        $this->assertStringContainsString('contentguardNavigateToField', $js);
        $this->assertStringContainsString('.acf-field[data-key="', $js);
        $this->assertStringContainsString('function navigateToField(', $js);
        $this->assertStringNotContainsString('toggleEditorPanelEnabled', $blockers);
        $this->assertStringContainsString('data-contentguard-core', $blockers);
        $this->assertStringContainsString('data-contentguard-field', $blockers);
        $this->assertStringContainsString('isClickableFailure', $blockers);
        $this->assertStringNotContainsString('toggleEditorPanelEnabled', $warnings);
        $this->assertStringContainsString('data-contentguard-core', $warnings);
        $this->assertStringContainsString('contentguardNavigateToField', $warnings);
    }

    public function testGutenbergCoreTitleAndContentUseCanvasDocumentsNotParentVisualEditor(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-field.js');

        $this->assertStringContainsString('function canvasDocuments(', $js);
        $this->assertStringContainsString('function firstMatchInCanvas(', $js);
        $this->assertStringContainsString('iframe[name="editor-canvas"]', $js);
        $this->assertStringContainsString('contentDocument', $js);
        $this->assertStringNotContainsString('.editor-visual-editor', $js);

        $this->assertMatchesRegularExpression(
            '/if \(fieldId === "title"\) \{(?:(?!\n    if \(fieldId).)*firstMatchInCanvas\(/s',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/if \(fieldId === "content"\) \{(?:(?!\n    if \(fieldId).)*firstMatchInCanvas\(/s',
            $js
        );

        preg_match('/if \(fieldId === "title"\) \{.*?\n    \}/s', $js, $title);
        $this->assertNotSame(array(), $title);
        $this->assertStringContainsString('.editor-post-title__input', $title[0]);
        $this->assertStringContainsString('h1.editor-post-title', $title[0]);
        $this->assertStringContainsString('h1.wp-block-post-title', $title[0]);
        $this->assertStringContainsString('.wp-block-post-title', $title[0]);
        $this->assertStringContainsString('[data-type="core/post-title"]', $title[0]);
        $this->assertStringContainsString('firstMatchInCanvas', $title[0]);

        preg_match('/if \(fieldId === "content"\) \{.*?\n    \}/s', $js, $content);
        $this->assertNotSame(array(), $content);
        $this->assertStringContainsString('firstMatchInCanvas', $content[0]);
        $this->assertStringContainsString('.is-root-container', $content[0]);
        $this->assertStringContainsString('.editor-styles-wrapper', $content[0]);
        $this->assertStringContainsString('[data-type="core/post-content"]', $content[0]);
        $this->assertStringContainsString('.block-editor-writing-flow', $content[0]);
        $this->assertStringNotContainsString('.editor-visual-editor', $content[0]);

        $this->assertDoesNotMatchRegularExpression(
            '/function navigateGutenbergExcerpt\([^)]*\)\s*\{(?:(?!\n  function ).)*firstMatchInCanvas\(/s',
            $js
        );
        $this->assertStringContainsString('openEditorPanel("featured-image")', $js);
        $this->assertStringContainsString('.editor-post-featured-image', $js);
        $this->assertStringContainsString('.acf-field[data-key="', $js);
        $this->assertStringContainsString('findFieldInRepeaterPath', $js);
        $this->assertStringContainsString('#title', $js);
        $this->assertStringContainsString('#postdivrich', $js);
    }
}
