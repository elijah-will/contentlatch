<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

use ContentLatch\Application\Integration\Integration;
use ContentLatch\Infrastructure\WordPress\CoreFieldCatalog;
use ContentLatch\Infrastructure\WordPress\CoreIntegration;
use ContentLatch\Infrastructure\WordPress\CoreIncomingValueProvider;
use ContentLatch\Infrastructure\WordPress\CoreStoredValueProvider;
use ContentLatch\Tests\Support\CoreCatalogFixtures;
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

    public function testExposesIncomingProviderForOwnedCoreIds(): void
    {
        $integration = CoreCatalogFixtures::integration();
        $types       = $integration->fieldTypesForPostType('post');
        $provider    = $integration->incomingProvider(
            array('title' => 'Incoming', 'field_123abc' => 'nope'),
            'post',
            $types
        );

        $this->assertInstanceOf(CoreIncomingValueProvider::class, $provider);
        $this->assertTrue($provider->has(CoreFieldCatalog::TITLE));
        $this->assertSame('Incoming', $provider->get(CoreFieldCatalog::TITLE));
        $this->assertFalse($provider->has('field_123abc'));
        $this->assertFalse($provider->has(CoreFieldCatalog::CONTENT));
    }
}
