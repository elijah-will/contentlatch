<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application\Integration;

use ContentLatch\Application\Integration\Integration;
use ContentLatch\Application\Integration\IntegrationRegistry;
use PHPUnit\Framework\TestCase;

final class IntegrationRegistryTest extends TestCase
{
    public function testAcfRegistersAndReportsAvailability(): void
    {
        $available = new Integration(Integration::ACF, 'ACF', true);
        $registry  = new IntegrationRegistry(array($available));

        $this->assertTrue($registry->has(Integration::ACF));
        $this->assertTrue($registry->isAvailable(Integration::ACF));
        $this->assertSame($available, $registry->get(Integration::ACF));
        $this->assertCount(1, $registry->all());
        $this->assertFalse($registry->has('yoast'));
        $this->assertFalse($registry->has('test'));
        $this->assertFalse($registry->isAvailable('yoast'));
    }

    public function testUnavailableAcfStaysRegistered(): void
    {
        $registry = new IntegrationRegistry(array(
            new Integration(Integration::ACF, 'ACF', false),
        ));

        $this->assertTrue($registry->has(Integration::ACF));
        $this->assertFalse($registry->isAvailable(Integration::ACF));
        $this->assertSame('acf', $registry->get(Integration::ACF)?->id);
    }
}
