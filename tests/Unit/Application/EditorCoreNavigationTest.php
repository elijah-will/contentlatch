<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\EditorCoreNavigation;
use ContentGuard\Application\EditorFieldNavigation;
use ContentGuard\Domain\FieldRef;
use PHPUnit\Framework\TestCase;

final class EditorCoreNavigationTest extends TestCase
{
    public function testAllowlistDoesNotWidenAcfFieldKeySafety(): void
    {
        $this->assertTrue(EditorCoreNavigation::isId('title'));
        $this->assertTrue(EditorCoreNavigation::isId('content'));
        $this->assertTrue(EditorCoreNavigation::isId('excerpt'));
        $this->assertTrue(EditorCoreNavigation::isId('featured_image'));
        $this->assertTrue(EditorCoreNavigation::isId('slug'));
        $this->assertTrue(EditorCoreNavigation::isId('author'));
        $this->assertFalse(EditorCoreNavigation::isId('field_description'));
        $this->assertFalse(EditorCoreNavigation::isId('post_title'));
        $this->assertFalse(EditorCoreNavigation::isId('field_title'));
        $this->assertFalse(FieldRef::isSafeFieldKey('title'));
        $this->assertFalse(EditorFieldNavigation::isSafeFieldKey('title'));
        $this->assertSame('', EditorFieldNavigation::navigableFieldKey('title'));
        $this->assertSame('title', EditorFieldNavigation::navigationId('title'));
        $this->assertSame('field_description', EditorFieldNavigation::navigationId('field_description'));
    }

    public function testGutenbergSupportsOnlyTheApprovedCoreFields(): void
    {
        $surface = EditorCoreNavigation::SURFACE_GUTENBERG;

        $this->assertTrue(EditorCoreNavigation::isSupported('title', $surface));
        $this->assertTrue(EditorCoreNavigation::isSupported('content', $surface));
        $this->assertTrue(EditorCoreNavigation::isSupported('excerpt', $surface));
        $this->assertTrue(EditorCoreNavigation::isSupported('featured_image', $surface));
        $this->assertFalse(EditorCoreNavigation::isSupported('slug', $surface));
        $this->assertFalse(EditorCoreNavigation::isSupported('author', $surface));
        $this->assertSame(
            array('title', 'content', 'excerpt', 'featured_image'),
            EditorCoreNavigation::idsForSurface($surface)
        );
    }

    public function testClassicSupportsApprovedCoreFieldsIncludingSlugAndAuthor(): void
    {
        $surface = EditorCoreNavigation::SURFACE_CLASSIC;

        $this->assertTrue(EditorCoreNavigation::isSupported('title', $surface));
        $this->assertTrue(EditorCoreNavigation::isSupported('content', $surface));
        $this->assertTrue(EditorCoreNavigation::isSupported('excerpt', $surface));
        $this->assertTrue(EditorCoreNavigation::isSupported('featured_image', $surface));
        $this->assertTrue(EditorCoreNavigation::isSupported('slug', $surface));
        $this->assertTrue(EditorCoreNavigation::isSupported('author', $surface));
        $this->assertFalse(EditorCoreNavigation::isSupported('field_description', $surface));
    }

    public function testClickableHtmlIsSurfaceSpecific(): void
    {
        $title = EditorFieldNavigation::clickableIssueHtml(
            'title',
            'Title',
            'This field is required.',
            'This field is required.',
            EditorCoreNavigation::SURFACE_GUTENBERG
        );
        $this->assertStringContainsString('data-contentguard-core="title"', $title);
        $this->assertStringContainsString('contentguard-warning-field', $title);
        $this->assertStringNotContainsString('data-contentguard-field', $title);

        $slugGutenberg = EditorFieldNavigation::clickableIssueHtml(
            'slug',
            'Slug',
            'This field is required.',
            'This field is required.',
            EditorCoreNavigation::SURFACE_GUTENBERG
        );
        $this->assertStringNotContainsString('<button', $slugGutenberg);
        $this->assertStringNotContainsString('data-contentguard-core', $slugGutenberg);
        $this->assertSame('Slug — This field is required.', $slugGutenberg);

        $slugClassic = EditorFieldNavigation::clickableIssueHtml(
            'slug',
            'Slug',
            'This field is required.',
            'This field is required.',
            EditorCoreNavigation::SURFACE_CLASSIC
        );
        $this->assertStringContainsString('data-contentguard-core="slug"', $slugClassic);

        $authorGutenberg = EditorFieldNavigation::clickableIssueHtml(
            'author',
            'Author',
            'This field is required.',
            'This field is required.',
            EditorCoreNavigation::SURFACE_GUTENBERG
        );
        $this->assertStringNotContainsString('<button', $authorGutenberg);

        $acf = EditorFieldNavigation::clickableIssueHtml(
            'field_description',
            'Recipe Description',
            'This field is required.',
            'This field is required.',
            EditorCoreNavigation::SURFACE_GUTENBERG
        );
        $this->assertStringContainsString('data-contentguard-field="field_description"', $acf);
        $this->assertStringNotContainsString('data-contentguard-core', $acf);
    }

    public function testAuditUrlsAcceptCoreIdsWithoutTreatingThemAsAcfKeys(): void
    {
        $edit = 'http://example.test/wp-admin/post.php?post=42&action=edit';

        $this->assertTrue(EditorFieldNavigation::isQueryTarget('title'));
        $this->assertTrue(EditorFieldNavigation::isQueryTarget('field_description'));
        $this->assertFalse(EditorFieldNavigation::isQueryTarget('not-a-field'));
        $this->assertSame(
            $edit . '&contentguard_field=title',
            EditorFieldNavigation::appendToEditUrl($edit, 'title')
        );
        $this->assertSame(
            'title',
            EditorFieldNavigation::requestedFieldKey(array('contentguard_field' => 'title'))
        );
        $this->assertSame(
            '',
            EditorFieldNavigation::requestedFieldKey(array('contentguard_field' => 'post_title'))
        );
    }
}
