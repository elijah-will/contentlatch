<?php
/**
 * Presentation grouping for findings that share content and rule.
 *
 * Does not change finding identity or persistence. Multiple validation_id
 * rows remain separate; this only combines them for display.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

use ContentGuard\Application\StatusPresentation;
use ContentGuard\Domain\RuleSeverity;

final class AuditFindingGroup
{
    /**
     * @param AuditFinding[] $findings
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $postType,
        public readonly int|string $ruleId,
        public readonly string $fieldKey,
        public readonly array $findings,
    ) {
    }

    /**
     * @param AuditFinding[] $findings
     * @return self[]
     */
    public static function group(array $findings): array
    {
        $buckets = array();
        $order   = array();

        foreach ($findings as $finding) {
            if (!$finding instanceof AuditFinding) {
                continue;
            }

            $key = $finding->postId . "\n" . (string) $finding->ruleId;
            if (!isset($buckets[$key])) {
                $buckets[$key] = array();
                $order[]       = $key;
            }

            $buckets[$key][] = $finding;
        }

        $groups = array();
        foreach ($order as $key) {
            $items = $buckets[$key];
            $first = $items[0];
            $groups[] = new self(
                $first->postId,
                $first->postType,
                $first->ruleId,
                $first->fieldKey,
                $items
            );
        }

        return $groups;
    }

    public function first(): AuditFinding
    {
        return $this->findings[0];
    }

    public function count(): int
    {
        return count($this->findings);
    }

    public function isMultiple(): bool
    {
        return $this->count() > 1;
    }

    public function blockingCount(): int
    {
        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->severity === RuleSeverity::Fail) {
                $count++;
            }
        }

        return $count;
    }

    public function warningCount(): int
    {
        return $this->count() - $this->blockingCount();
    }

    /**
     * @return array<string, AuditFinding[]>
     */
    public function findingsByField(): array
    {
        $buckets = array();
        $order   = array();

        foreach ($this->findings as $finding) {
            $key = $finding->fieldKey;
            if (!isset($buckets[$key])) {
                $buckets[$key] = array();
                $order[]       = $key;
            }

            $buckets[$key][] = $finding;
        }

        $grouped = array();
        foreach ($order as $key) {
            $grouped[$key] = $buckets[$key];
        }

        return $grouped;
    }

    /**
     * @param array<string, string> $fieldLabels
     * @return list<array{label: string, fieldKey: string, messages: list<string>}>
     */
    public function fieldIssues(array $fieldLabels): array
    {
        $issues = array();

        foreach ($this->findingsByField() as $fieldKey => $items) {
            $messages = array();
            foreach ($items as $finding) {
                if ($finding->message === '' || in_array($finding->message, $messages, true)) {
                    continue;
                }

                $messages[] = $finding->message;
            }

            $issues[] = array(
                'label'    => $fieldLabels[(string) $this->ruleId . ':' . $fieldKey] ?? $fieldKey,
                'fieldKey' => $fieldKey,
                'messages' => $messages,
            );
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = array();
        foreach ($this->findings as $finding) {
            if ($finding->message === '') {
                continue;
            }

            $messages[] = $finding->message;
        }

        return array_values(array_unique($messages));
    }

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        $hasFail    = false;
        $hasWarning = false;

        foreach ($this->findings as $finding) {
            if ($finding->severity === RuleSeverity::Fail) {
                $hasFail = true;
            } else {
                $hasWarning = true;
            }
        }

        $statuses = array();
        if ($hasFail) {
            $statuses[] = StatusPresentation::fromSeverity(RuleSeverity::Fail);
        }
        if ($hasWarning) {
            $statuses[] = StatusPresentation::fromSeverity(RuleSeverity::Warning);
        }

        return $statuses;
    }

    public function primaryStatus(): string
    {
        $statuses = $this->statuses();

        return $statuses[0] ?? 'blocking';
    }
}
