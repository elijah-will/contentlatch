<?php
/**
 * Converts stored WordPress Core values into domain-safe scalars.
 *
 * Core-specific: empty Gutenberg markup becomes empty, missing thumbnails
 * become empty, and author 0 becomes empty. ACF normalization is unchanged.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class CoreValueNormalizer
{
    public function normalize(string $fieldId, mixed $value): mixed
    {
        return match ($fieldId) {
            CoreFieldCatalog::CONTENT        => $this->normalizeContent($value),
            CoreFieldCatalog::FEATURED_IMAGE => $this->normalizeFeaturedImage($value),
            CoreFieldCatalog::AUTHOR         => $this->normalizeAuthor($value),
            default                          => $this->normalizeText($value),
        };
    }

    private function normalizeContent(mixed $value): mixed
    {
        if ($this->isComplex($value) || $this->isAbsent($value)) {
            return null;
        }

        $text = html_entity_decode(
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Intentional validation normalization (visible text), not output escaping; wp_strip_all_tags would drop script/style contents and change empty/required evaluation.
            strip_tags((string) $value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $text = trim($text);
        if ($text === '' || $this->isWhitespaceOnly($text)) {
            return null;
        }

        return $text;
    }

    private function normalizeFeaturedImage(mixed $value): mixed
    {
        if ($this->isComplex($value)) {
            return false;
        }

        if ($this->isAbsent($value) || $value === 0 || $value === '0' || $value === -1 || $value === '-1') {
            return false;
        }

        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return true;
        }

        return false;
    }

    private function normalizeAuthor(mixed $value): mixed
    {
        if ($this->isComplex($value) || $this->isAbsent($value) || $value === 0 || $value === '0') {
            return null;
        }

        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }

        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }

        return (string) $id;
    }

    private function normalizeText(mixed $value): mixed
    {
        if ($this->isComplex($value) || $this->isAbsent($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function isAbsent(mixed $value): bool
    {
        return $value === null || $value === false || $value === '' || $value === array();
    }

    private function isComplex(mixed $value): bool
    {
        return is_object($value) || is_array($value);
    }

    private function isWhitespaceOnly(string $text): bool
    {
        return preg_replace('/[\s\x{00A0}]+/u', '', $text) === '';
    }
}
