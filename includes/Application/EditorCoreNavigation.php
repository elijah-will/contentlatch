<?php
/**
 * Allowlisted WordPress Core editor navigation.
 *
 * Presentation only. Does not change FieldRef, catalogs, or evaluation.
 * ACF field-key navigation stays in EditorFieldNavigation.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

use ContentLatch\Infrastructure\WordPress\CoreFieldCatalog;

final class EditorCoreNavigation
{
    public const SURFACE_GUTENBERG = 'gutenberg';
    public const SURFACE_CLASSIC   = 'classic';

    /**
     * @var list<string>
     */
    private const GUTENBERG = array(
        CoreFieldCatalog::TITLE,
        CoreFieldCatalog::CONTENT,
        CoreFieldCatalog::EXCERPT,
        CoreFieldCatalog::FEATURED_IMAGE,
    );

    /**
     * @var list<string>
     */
    private const CLASSIC = array(
        CoreFieldCatalog::TITLE,
        CoreFieldCatalog::CONTENT,
        CoreFieldCatalog::EXCERPT,
        CoreFieldCatalog::FEATURED_IMAGE,
        CoreFieldCatalog::SLUG,
        CoreFieldCatalog::AUTHOR,
    );

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return CoreFieldCatalog::ids();
    }

    public static function isId(?string $fieldId): bool
    {
        $fieldId = trim((string) $fieldId);

        return $fieldId !== '' && in_array($fieldId, self::ids(), true);
    }

    public static function id(?string $fieldId): string
    {
        $fieldId = trim((string) $fieldId);

        return self::isId($fieldId) ? $fieldId : '';
    }

    public static function isSupported(?string $fieldId, string $surface): bool
    {
        $fieldId = self::id($fieldId);
        if ($fieldId === '') {
            return false;
        }

        return in_array($fieldId, self::idsForSurface($surface), true);
    }

    /**
     * @return list<string>
     */
    public static function idsForSurface(string $surface): array
    {
        return $surface === self::SURFACE_GUTENBERG ? self::GUTENBERG : self::CLASSIC;
    }

    public static function normalizeSurface(?string $surface): string
    {
        return $surface === self::SURFACE_GUTENBERG
            ? self::SURFACE_GUTENBERG
            : self::SURFACE_CLASSIC;
    }

    public static function currentSurface(): string
    {
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if (is_object($screen) && !empty($screen->is_block_editor)) {
                return self::SURFACE_GUTENBERG;
            }
        }

        return self::SURFACE_CLASSIC;
    }

    /**
     * @return array{gutenberg: list<string>, classic: list<string>}
     */
    public static function clientConfig(): array
    {
        return array(
            'gutenberg' => self::GUTENBERG,
            'classic'   => self::CLASSIC,
        );
    }

    public static function triggerAttributes(string $fieldId): string
    {
        $fieldId = self::id($fieldId);
        if ($fieldId === '') {
            return '';
        }

        $attr = self::escapeAttr($fieldId);

        return 'data-contentlatch-core="' . $attr . '"';
    }

    private static function escapeAttr(string $value): string
    {
        if (function_exists('esc_attr')) {
            return esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
