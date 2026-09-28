<?php
/**
 * Small integration descriptor. Not a capability god-object.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Integration;

defined('ABSPATH') || exit;

final class Integration
{
    public const ACF  = 'acf';
    public const CORE = 'core';

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $available,
    ) {
    }
}
