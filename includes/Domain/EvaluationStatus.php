<?php
/**
 * Per-validation or per-skip evaluation status.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain;

enum EvaluationStatus: string
{
    case Skipped = 'skipped';
    case Passed  = 'passed';
    case Warning = 'warning';
    case Failed  = 'failed';
}
