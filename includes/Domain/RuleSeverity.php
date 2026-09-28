<?php
/**
 * Rule severity.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain;

enum RuleSeverity: string
{
    case Fail    = 'fail';
    case Warning = 'warning';
}
