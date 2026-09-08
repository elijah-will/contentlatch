<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\Integration\CompositeFieldCatalog;
use ContentGuard\Application\Integration\Integration;
use ContentGuard\Dependencies;
use ContentGuard\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginIntegrationCompositionTest extends TestCase
{
    public function testPluginComposesTheAcfIntegrationThroughTheRegistry(): void
    {
        $plugin = new Plugin(new Dependencies());

        $this->assertTrue($plugin->integrations()->has(Integration::ACF));
        $this->assertSame(
            $plugin->dependencies()->acfMeetsMinimum(),
            $plugin->integrations()->isAvailable(Integration::ACF)
        );
        $this->assertInstanceOf(CompositeFieldCatalog::class, $plugin->fieldCatalog());
        $this->assertSame(Integration::ACF, $plugin->acfIntegration()->descriptor()->id);
        $this->assertFalse($plugin->integrations()->has('test'));
        $this->assertFalse($plugin->integrations()->has('yoast'));
        $this->assertSame($plugin->fieldCatalog(), $plugin->fieldCatalog());
    }

    public function testRuleDocumentFactoryDependsOnTheCatalogNotAcfPrefixes(): void
    {
        $factory = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Application/RuleDocumentFactory.php');
        $plugin  = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Plugin.php');

        $this->assertStringNotContainsString("str_starts_with(\$key, 'field_')", $factory);
        $this->assertStringNotContainsString('AcfFieldCatalog', $factory);
        $this->assertStringContainsString('FieldCatalog', $factory);
        $this->assertStringContainsString('$this->fieldCatalog()', $plugin);
        $this->assertStringNotContainsString('FakeIntegration', $plugin);
    }

    public function testRuleEngineStaysFreeOfIntegrationBranches(): void
    {
        $engine = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Domain/RuleEngine.php');

        $this->assertStringNotContainsString('AcfFieldCatalog', $engine);
        $this->assertStringNotContainsString('if ACF', $engine);
        $this->assertStringNotContainsString('Yoast', $engine);
        $this->assertStringNotContainsString('WooCommerce', $engine);
        $this->assertStringNotContainsString('FakeIntegration', $engine);
        $this->assertStringNotContainsString('IntegrationRegistry', $engine);
    }
}
