<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\I18n;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase
{
    public function testTranslateReturnsEnglishWhenGettextUnavailable(): void
    {
        $this->assertSame('This field is required.', I18n::translate('This field is required.'));
        $this->assertSame('Content warning.', I18n::translate('Content warning.'));
    }

    public function testSprintfFormatsTranslatedTemplates(): void
    {
        $this->assertSame(
            'Title is required.',
            I18n::sprintf(I18n::translate('%s is required.'), 'Title')
        );
        $this->assertSame(
            '1 of 3 content items checked',
            I18n::sprintf(I18n::translate('%1$d of %2$d content items checked'), 1, 3)
        );
    }

    public function testTranslatePluralPicksSingularOrPluralWithoutWordPress(): void
    {
        $this->assertSame(
            '%d content item with warnings',
            I18n::translatePlural('%d content item with warnings', '%d content items with warnings', 1)
        );
        $this->assertSame(
            '%d content items with warnings',
            I18n::translatePlural('%d content item with warnings', '%d content items with warnings', 3)
        );
    }
}
