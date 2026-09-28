<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application;

use ContentLatch\Application\AdminPresentation;
use PHPUnit\Framework\TestCase;

final class AdminPresentationTest extends TestCase
{
    public function testPostTypeLabelPrefersTheProvidedName(): void
    {
        $this->assertSame(
            'Recipe',
            AdminPresentation::postTypeLabel('recipe', array('recipe' => 'Recipe'))
        );
    }

    public function testPostTypeLabelFallsBackToTheSlug(): void
    {
        $this->assertSame('recipe', AdminPresentation::postTypeLabel('recipe'));
        $this->assertSame('recipe', AdminPresentation::postTypeLabel('recipe', array('page' => 'Page')));
        $this->assertSame('recipe', AdminPresentation::resolvePostTypeLabel('recipe'));
    }
}
