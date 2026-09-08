<?php
/**
 * Merges integration catalogs. Ownership stays on catalog entries, not FieldRef.
 *
 * fieldsForPostType() keeps every entry. fieldTypesForPostType() is keyed by
 * resolution id, so duplicate ids collapse. Adapters must use unique ids.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Integration;

final class CompositeFieldCatalog implements FieldCatalog
{
    /**
     * @param list<FieldCatalog> $catalogs
     */
    public function __construct(private array $catalogs)
    {
    }

    public function fieldsForPostType(string $postType): array
    {
        $fields = array();

        foreach ($this->catalogs as $catalog) {
            if (!$catalog instanceof FieldCatalog) {
                continue;
            }

            foreach ($catalog->fieldsForPostType($postType) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    public function fieldTypesForPostType(string $postType): array
    {
        $types = array();

        foreach ($this->catalogs as $catalog) {
            if (!$catalog instanceof FieldCatalog) {
                continue;
            }

            foreach ($catalog->fieldTypesForPostType($postType) as $id => $type) {
                $types[$id] = $type;
            }
        }

        return $types;
    }
}
