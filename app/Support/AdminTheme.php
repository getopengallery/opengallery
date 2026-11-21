<?php

namespace App\Support;

use App\Extensions\ThemeManager;
use Filament\Support\Colors\Color;

/**
 * Colour palettes for the Filament admin, derived from the active theme's
 * resolved colours (base tokens + chosen palette + custom colours), so the
 * admin follows the same theme settings as the site.
 *
 * Corner radius and the dark-mode primary button are applied in CSS
 * (resources/views/filament/partials/theme-styles.blade.php).
 */
class AdminTheme
{
    /** @return array<int, string> primary palette with shade 600 pinned to the theme's primary colour */
    public static function primary(): array
    {
        $themes = app(ThemeManager::class);
        $hex = $themes->setting('admin.primary') ?? self::lightToken('primary') ?? '#1A1A1A';

        return self::pinned(self::hex($hex) ?? '#1A1A1A', [500 => 0.12, 600 => 0.0, 700 => -0.05]);
    }

    /**
     * Greys with the hue of the theme's secondary text colour (so a slate
     * theme gets bluish greys), with the darkest shades set to the theme's
     * dark-mode background, surface and lines.
     *
     * @return array<int, string>
     */
    public static function gray(): array
    {
        $muted = self::hex(self::lightToken('muted') ?? '') ?? '#6B6B6B';
        [, $dark] = app(ThemeManager::class)->resolvedVariables();

        $palette = self::isAchromatic($muted) ? Color::Neutral : Color::hex($muted);

        foreach ([950 => '--og-canvas', 900 => '--og-surface', 800 => '--og-line'] as $shade => $token) {
            if ($hex = self::hex((string) ($dark[$token] ?? ''))) {
                $palette[$shade] = Color::convertToOklch($hex);
            }
        }

        return $palette;
    }

    /** "#RRGGBB" or "R G B" → "#RRGGBB"; anything else → null. */
    public static function hex(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return strtoupper($value);
        }

        if (preg_match('/^(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})$/', $value, $m)) {
            return sprintf('#%02X%02X%02X', ...array_map(fn ($c) => min(255, (int) $c), array_slice($m, 1)));
        }

        return null;
    }

    private static function lightToken(string $token): ?string
    {
        [$light] = app(ThemeManager::class)->resolvedVariables();

        return isset($light["--og-{$token}"]) ? (string) $light["--og-{$token}"] : null;
    }

    private static function isAchromatic(string $hex): bool
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return max($r, $g, $b) - min($r, $g, $b) <= 6;
    }

    /**
     * Filament's generated palettes normalise lightness, so a dark theme
     * primary would turn into mid-grey buttons. Pin shade 600 (the button
     * colour) to the exact colour and derive its neighbours.
     *
     * @param  array<int, float>  $offsets  lightness offset per shade
     * @return array<int, string>
     */
    private static function pinned(string $hex, array $offsets): array
    {
        $palette = Color::hex($hex);

        if (! preg_match('/oklch\(([\d.]+)\s+([\d.]+)\s+([\d.]+)\)/', Color::convertToOklch($hex), $m)) {
            return $palette;
        }

        [, $l, $c, $h] = $m;

        // array_replace, not spread: spreading int-keyed arrays renumbers them.
        return array_replace($palette, array_map(
            fn (float $offset) => sprintf('oklch(%.3f %s %s)', max(0.1, min(0.97, $l + $offset)), $c, $h),
            $offsets,
        ));
    }
}
