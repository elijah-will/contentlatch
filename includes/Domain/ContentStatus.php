<?php
/**
 * Post-level evaluation rollup.
 *
 * Distinguishes an actual pass from content that was never evaluated
 * (no applicable rules).
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain;

enum ContentStatus: string
{
    case Passed        = 'passed';
    case Warning       = 'warning';
    case Failed        = 'failed';
    case NotEvaluated  = 'not_evaluated';
}
