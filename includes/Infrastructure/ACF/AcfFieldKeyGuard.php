<?php
/**
 * Shared field-key allowlisting for ACF value providers.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\ACF;

defined('ABSPATH') || exit;

trait AcfFieldKeyGuard
{
    /**
     * @param array<string, string> $fieldTypes Canonical field key => ACF type.
     */
    private function isAllowedFieldKey(string $fieldId, array $fieldTypes): bool
    {
        if ($fieldId === '' || !str_starts_with($fieldId, 'field_')) {
            return false;
        }

        return isset($fieldTypes[$fieldId]);
    }
}
