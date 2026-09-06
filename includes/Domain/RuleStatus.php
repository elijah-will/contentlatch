<?php
/**
 * Rule active/inactive status.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

enum RuleStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
