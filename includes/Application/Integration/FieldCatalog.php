<?php
/**
 * Post-type field discovery for the Rule Builder and evaluation composition.
 *
 * ACF FieldDefinition stays inside the ACF adapter. This catalog exposes the
 * array shape RuleDocumentFactory already consumes.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Integration;

interface FieldCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsForPostType(string $postType): array;

    /**
     * @return array<string, string> Resolution id => field type.
     */
    public function fieldTypesForPostType(string $postType): array;
}
