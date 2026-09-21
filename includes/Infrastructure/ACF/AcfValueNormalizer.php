<?php
/**
 * Converts ACF field values into domain-safe scalars.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

defined('ABSPATH') || exit;

final class AcfValueNormalizer
{
    public function normalize(mixed $value, string $fieldType): mixed
    {
        return match ($fieldType) {
            'true_false' => $this->normalizeTrueFalse($value),
            'wysiwyg'    => $this->normalizeWysiwyg($value),
            'number', 'range' => $this->normalizeNumber($value),
            'select', 'radio', 'button_group' => $this->normalizeChoice($value),
            default => $this->normalizeScalar($value),
        };
    }

    private function normalizeTrueFalse(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function normalizeWysiwyg(mixed $value): mixed
    {
        if ($this->isComplex($value)) {
            return null;
        }

        if ($this->isAbsent($value)) {
            return null;
        }

        $text = html_entity_decode(
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Intentional validation normalization (visible text), not output escaping; wp_strip_all_tags would drop script/style contents and change empty/required evaluation.
            strip_tags((string) $value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return $text;
    }

    private function normalizeNumber(mixed $value): mixed
    {
        if ($this->isComplex($value) || $this->isAbsent($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (!is_string($value) || !is_numeric($value)) {
            return null;
        }

        if ($value !== trim($value) || str_contains($value, ',')) {
            return null;
        }

        return $value;
    }

    private function normalizeChoice(mixed $value): mixed
    {
        if (is_array($value) && $this->isValueLabelPair($value)) {
            return $this->normalizeScalar($value['value']);
        }

        if ($this->isComplex($value)) {
            return null;
        }

        return $this->normalizeScalar($value);
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if ($this->isComplex($value)) {
            return null;
        }

        if ($this->isAbsent($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return $value;
    }

    private function isAbsent(mixed $value): bool
    {
        return $value === null || $value === false || $value === '' || $value === array();
    }

    private function isComplex(mixed $value): bool
    {
        if (is_object($value)) {
            return true;
        }

        return is_array($value) && !$this->isValueLabelPair($value);
    }

    /**
     * @param array<mixed> $value
     */
    private function isValueLabelPair(array $value): bool
    {
        if (!array_key_exists('value', $value)) {
            return false;
        }

        $keys = array_keys($value);
        foreach ($keys as $key) {
            if ($key !== 'value' && $key !== 'label') {
                return false;
            }
        }

        return !is_array($value['value']) && !is_object($value['value']);
    }
}
