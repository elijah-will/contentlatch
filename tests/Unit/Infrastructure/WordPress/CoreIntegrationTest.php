<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Application\Integration\Integration;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Infrastructure\WordPress\CoreIntegration;
use ContentGuard\Infrastructure\WordPress\CoreStoredValueProvider;
use ContentGuard\Tests\Support\CoreCatalogFixtures;
use PHPUnit\Framework\TestCase;

final class CoreIntegrationTest extends TestCase
{
    public function testDescriptorIdentifiesCoreAndIsAlwaysAvailable(): void
    {
        $integration = CoreCatalogFixtures::integration();

        $this->assertSame(Integration::CORE, $integration->descriptor()->id);
        $this->assertSame('core', $integration->descriptor()->id);
        $this->assertSame('WordPress', $integration->descriptor()->label);
        $this->assertTrue($integration->isAvailable());
        $this->assertTrue($integration->descriptor()->available);
        $this->assertSame(CoreIntegration::ID, Integration::CORE);
        $this->assertSame(CoreIntegration::LABEL, 'WordPress');
    }

    public function testWordpressFactoryIsAlwaysAvailable(): void
    {
        $integration = CoreIntegration::wordpress();

        $this->assertTrue($integration->isAvailable());
        $this->assertInstanceOf(CoreFieldCatalog::class, $integration->nativeCatalog());
        $this->assertSame(Integration::CORE, $integration->descriptor()->id);
    }

    public function testExposesCatalogArraysWithCoreCompositionMetadata(): void
    {
        $integration = CoreCatalogFixtures::integration();
        $fields      = $integration->fieldsForPostType('post');
        $byId        = array();
        foreach ($fields as $field) {
            $byId[(string) $field['key']] = $field;
        }

        $this->assertArrayHasKey(CoreFieldCatalog::TITLE, $byId);
        $this->assertSame(Integration::CORE, $byId[CoreFieldCatalog::TITLE]['integration']);
        $this->assertSame('WordPress', $byId[CoreFieldCatalog::TITLE]['group_label']);
        $this->assertSame('Title', $byId[CoreFieldCatalog::TITLE]['label']);
        $this->assertSame(
            CoreFieldCatalog::FIELDS[CoreFieldCatalog::TITLE]['type'],
            $integration->fieldTypesForPostType('post')[CoreFieldCatalog::TITLE]
        );
    }

    public function testExposesStoredProviderForOwnedCoreIds(): void
    {
        $integration = CoreCatalogFixtures::integration();
        $types       = $integration->fieldTypesForPostType('post');
        $provider    = $integration->storedProvider(
            9,
            'post',
            $types,
            static fn (string $id): mixed => $id === CoreFieldCatalog::TITLE ? 'Hello' : null
        );

        $this->assertInstanceOf(CoreStoredValueProvider::class, $provider);
        $this->assertTrue($provider->has(CoreFieldCatalog::TITLE));
        $this->assertSame('Hello', $provider->get(CoreFieldCatalog::TITLE));
        $this->assertFalse($provider->has('field_123abc'));
    }
}
