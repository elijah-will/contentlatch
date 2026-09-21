<?php
/**
 * Nested Repeater coordinates on audit findings.
 *
 * Instance context keeps one chain: repeater_rows[0] outer, [1] inner.
 * A collapsed finding stores every failed chain under context.repeater_rows.
 * Snapshot text encodes display-row pairs so coordinates survive schema v2.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

defined('ABSPATH') || exit;

use ContentGuard\Domain\EvaluationResult;

final class AuditRepeaterCoordinates
{
    /**
     * @param EvaluationResult[] $results
     * @return list<list<array<string, mixed>>>
     */
    public static function cellsFromResults(array $results): array
    {
        $cells = array();
        $seen  = array();

        foreach ($results as $result) {
            if (!$result instanceof EvaluationResult) {
                continue;
            }

            $chain = self::instanceChain($result->context);
            $token = self::pairToken($chain);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $cells[]      = $chain;
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public static function instanceChain(array $context): array
    {
        $rows = $context['repeater_rows'] ?? null;
        if (!is_array($rows) || self::isCellList($rows)) {
            return array();
        }

        $outer = self::sanitizeCoordinate($rows[0] ?? null);
        $inner = self::sanitizeCoordinate($rows[1] ?? null);
        if ($outer === array() || $inner === array()) {
            return array();
        }

        return array($outer, $inner);
    }

    /**
     * @param list<list<array<string, mixed>>> $cells
     * @return array<string, mixed>
     */
    public static function contextFromCells(array $cells): array
    {
        return $cells === array() ? array() : array('repeater_rows' => $cells);
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    public static function cellsFromFinding(AuditFinding $finding): array
    {
        $structured = self::structuredCellsFromFinding($finding);
        if ($structured !== array()) {
            return $structured;
        }

        return self::cellsFromSnapshot($finding->message);
    }

    /**
     * Persisted nested coordinates only. Does not read snapshot text.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function structuredCellsFromFinding(AuditFinding $finding): array
    {
        $rows = $finding->context['repeater_rows'] ?? null;
        if (!is_array($rows) || $rows === array()) {
            return array();
        }

        if (self::isCellList($rows)) {
            return self::sanitizeCellList($rows);
        }

        $chain = self::instanceChain(array('repeater_rows' => $rows));

        return $chain === array() ? array() : array($chain);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function pairsFromFinding(AuditFinding $finding): array
    {
        $pairs = array();
        foreach (self::cellsFromFinding($finding) as $chain) {
            $outer = (int) ($chain[0]['display_row'] ?? 0);
            $inner = (int) ($chain[1]['display_row'] ?? 0);
            if ($outer > 0 && $inner > 0) {
                $pairs[] = array($outer, $inner);
            }
        }

        return $pairs;
    }

    /**
     * @param list<list<array<string, mixed>>> $cells
     */
    public static function formatSnapshot(string $base, array $cells): string
    {
        $tokens = array();
        $seen   = array();
        foreach ($cells as $chain) {
            $token = self::pairToken($chain);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $tokens[]     = $token;
        }

        if ($tokens === array()) {
            return $base;
        }

        if (count($tokens) === 1) {
            return sprintf('%s in row %s.', rtrim($base, '.'), $tokens[0]);
        }

        return sprintf(
            '%s in %d rows (rows %s).',
            rtrim($base, '.'),
            count($tokens),
            implode(', ', $tokens)
        );
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    /**
     * @param list<string> $messages
     * @return list<string>
     */
    public static function tokensFromMessages(array $messages): array
    {
        $tokens = array();
        foreach ($messages as $message) {
            foreach (self::cellsFromSnapshot((string) $message) as $chain) {
                $token = self::pairToken($chain);
                if ($token !== '') {
                    $tokens[$token] = $token;
                }
            }
        }

        return array_values($tokens);
    }

    /**
     * @param list<string> $messages
     */
    public static function firstSnapshotWithPairs(array $messages): string
    {
        foreach ($messages as $message) {
            $message = (string) $message;
            if (self::cellsFromSnapshot($message) !== array()) {
                return $message;
            }
        }

        return '';
    }

    public static function messageWithoutPairs(string $message): string
    {
        $stripped = preg_replace('/\s+in\s+\d+\s+rows\s+\(rows\s+\d+\/\d+(?:\s*,\s*\d+\/\d+)*\)\.?\s*$/i', '.', $message);
        if (is_string($stripped) && $stripped !== '') {
            $message = $stripped;
        }

        $stripped = preg_replace('/\s+in\s+row\s+\d+\/\d+\.?\s*$/i', '.', $message);

        return is_string($stripped) && $stripped !== '' ? $stripped : $message;
    }

    public static function cellsFromSnapshot(string $message): array
    {
        if (preg_match('/\bin\s+row\s+(\d+)\/(\d+)\b/i', $message, $single)) {
            $outer = (int) $single[1];
            $inner = (int) $single[2];
            if ($outer > 0 && $inner > 0) {
                return array(
                    array(
                        array('display_row' => $outer),
                        array('display_row' => $inner),
                    ),
                );
            }
        }

        if (!preg_match('/\(rows?\s+((?:\d+\/\d+)(?:\s*,\s*\d+\/\d+)*)\)/i', $message, $matches)) {
            return array();
        }

        $cells = array();
        foreach (preg_split('/\s*,\s*/', (string) $matches[1]) ?: array() as $token) {
            if (!preg_match('/^(\d+)\/(\d+)$/', $token, $pair)) {
                continue;
            }

            $outer = (int) $pair[1];
            $inner = (int) $pair[2];
            if ($outer <= 0 || $inner <= 0) {
                continue;
            }

            $cells[] = array(
                array('display_row' => $outer),
                array('display_row' => $inner),
            );
        }

        return $cells;
    }

    /**
     * @param list<array<string, mixed>> $chain
     */
    public static function pairToken(array $chain): string
    {
        $outer = (int) ($chain[0]['display_row'] ?? 0);
        $inner = (int) ($chain[1]['display_row'] ?? 0);

        return ($outer > 0 && $inner > 0) ? $outer . '/' . $inner : '';
    }

    /**
     * @param list<mixed> $rows
     */
    private static function isCellList(array $rows): bool
    {
        $first = $rows[0] ?? null;

        return is_array($first) && !isset($first['display_row']) && isset($first[0]) && is_array($first[0]);
    }

    /**
     * @param list<mixed> $rows
     * @return list<list<array<string, mixed>>>
     */
    private static function sanitizeCellList(array $rows): array
    {
        $cells = array();
        $seen  = array();

        foreach ($rows as $row) {
            $chain = self::instanceChain(array('repeater_rows' => is_array($row) ? $row : array()));
            $token = self::pairToken($chain);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $cells[]      = $chain;
        }

        return $cells;
    }

    /**
     * @return array<string, mixed>
     */
    private static function sanitizeCoordinate(mixed $row): array
    {
        if (!is_array($row)) {
            return array();
        }

        $display = $row['display_row'] ?? null;
        $display = is_int($display) || (is_numeric($display) && (int) $display > 0)
            ? (int) $display
            : 0;
        if ($display <= 0) {
            return array();
        }

        $coordinate = array('display_row' => $display);

        foreach (array('repeater', 'key') as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $coordinate[$field] = $value;
            }
        }

        if (isset($row['index']) && (is_int($row['index']) || is_numeric($row['index']))) {
            $coordinate['index'] = (int) $row['index'];
        }

        return $coordinate;
    }
}
