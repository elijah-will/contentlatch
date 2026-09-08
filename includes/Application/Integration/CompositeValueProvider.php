<?php
/**
 * Routes field reads to the first provider that owns the id.
 * Providers must only has() their own field ids.
 *
 * Unavailable integrations are omitted from the provider list by composition.
 * FieldValueProviderInterface cannot distinguish "unavailable" from "missing";
 * evaluating a required rule against a missing id still fails as empty.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Integration;

use ContentGuard\Domain\Contracts\FieldValueProviderInterface;

final class CompositeValueProvider implements FieldValueProviderInterface
{
    /**
     * @param list<FieldValueProviderInterface> $providers
     */
    public function __construct(private array $providers)
    {
    }

    public function has(string $fieldId): bool
    {
        return $this->owner($fieldId) !== null;
    }

    public function get(string $fieldId): mixed
    {
        $owner = $this->owner($fieldId);

        return $owner !== null ? $owner->get($fieldId) : null;
    }

    public function instances(string $fieldId): array
    {
        $owner = $this->owner($fieldId);

        return $owner !== null ? $owner->instances($fieldId) : array();
    }

    private function owner(string $fieldId): ?FieldValueProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof FieldValueProviderInterface && $provider->has($fieldId)) {
                return $provider;
            }
        }

        return null;
    }
}
