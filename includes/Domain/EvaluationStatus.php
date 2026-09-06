<?php
/**
 * Per-validation or per-skip evaluation status.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

enum EvaluationStatus: string
{
    case Skipped = 'skipped';
    case Passed  = 'passed';
    case Warning = 'warning';
    case Failed  = 'failed';
}
