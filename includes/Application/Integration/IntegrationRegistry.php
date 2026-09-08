<?php
/**
 * Holds registered integrations. ACF is the only member in Phase 11A.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Integration;

final class IntegrationRegistry
{
    /**
     * @var array<string, Integration>
     */
    private array $integrations = array();

    /**
     * @param list<Integration> $integrations
     */
    public function __construct(array $integrations = array())
    {
        foreach ($integrations as $integration) {
            $this->register($integration);
        }
    }

    public function register(Integration $integration): void
    {
        $this->integrations[$integration->id] = $integration;
    }

    public function get(string $id): ?Integration
    {
        return $this->integrations[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->integrations[$id]);
    }

    public function isAvailable(string $id): bool
    {
        $integration = $this->get($id);

        return $integration !== null && $integration->available;
    }

    /**
     * @return list<Integration>
     */
    public function all(): array
    {
        return array_values($this->integrations);
    }
}
