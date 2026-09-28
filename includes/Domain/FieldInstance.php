<?php
/**
 * One evaluation-time field value. Repeater and Flexible Content rows use
 * this; scalars use one instance.
 *
 * Row identity lives here only. It is never stored as FieldRef.key or finding identity.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain;

final class FieldInstance
{
    /**
     * @param array<string, mixed> $context Evaluation-time metadata (row_key, row_index, display_row, input_name).
     */
    public function __construct(
        public readonly mixed $value,
        public readonly array $context = array(),
    ) {
    }
}
