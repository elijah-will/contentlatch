<?php
/**
 * Rule severity.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

enum RuleSeverity: string
{
    case Fail    = 'fail';
    case Warning = 'warning';
}
