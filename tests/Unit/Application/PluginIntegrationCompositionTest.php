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
    public function testPluginComposesCoreAndAcfThroughTheRegistry(): void
    {
        $plugin = new Plugin(new Dependencies());

        $this->assertTrue($plugin->integrations()->has(Integration::CORE));
        $this->assertTrue($plugin->integrations()->has(Integration::ACF));
        $this->assertTrue($plugin->integrations()->isAvailable(Integration::CORE));
        $this->assertSame(
            $plugin->dependencies()->acfMeetsMinimum(),
            $plugin->integrations()->isAvailable(Integration::ACF)
        );
        $this->assertInstanceOf(CompositeFieldCatalog::class, $plugin->fieldCatalog());
        $this->assertSame(Integration::CORE, $plugin->coreIntegration()->descriptor()->id);
        $this->assertSame('WordPress', $plugin->coreIntegration()->descriptor()->label);
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
        $this->assertStringContainsString('CoreIntegration::wordpress()', $plugin);
        $this->assertStringContainsString('$core->storedProvider', $plugin);
        $this->assertStringContainsString('$acf->storedProvider', $plugin);
        $this->assertStringNotContainsString('FakeIntegration', $plugin);
        $this->assertStringNotContainsString("add_action('save_post'", $plugin);
        $this->assertStringNotContainsString('rest_pre_insert_', $plugin);
        $this->assertStringNotContainsString('rest_after_insert_', $plugin);
        $this->assertStringNotContainsString('wp_insert_post_data', $plugin);
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
        $this->assertStringNotContainsString('CoreIncomingValueProvider', $engine);
        $this->assertStringNotContainsString('CoreIntegration', $engine);
    }
}
