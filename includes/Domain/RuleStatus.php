<?php
/**
 * Rule active/inactive status.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain;

enum RuleStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
