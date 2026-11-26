<?php

namespace App\Support;

/**
 * Turns a browser's user agent into a short label ("Firefox on macOS") for
 * the list of a person's sessions. Only this label is stored, never the raw
 * user agent: it is coarse enough not to single out a device on its own.
 */
class UserAgentLabel
{
    /** Longest label stored, matching nothing but well-known names. */
    public const MAX_LENGTH = 60;

    /** Browsers in matching order: Edge and Opera also say "Chrome", Chrome also says "Safari". */
    private const BROWSERS = [
        'Edge' => '/\b(Edg|EdgA|EdgiOS|Edge)\//',
        'Opera' => '/\b(OPR|Opera|OPT)\//',
        'Samsung Internet' => '/\bSamsungBrowser\//',
        'Vivaldi' => '/\bVivaldi\//',
        'Firefox' => '/\b(Firefox|FxiOS)\//',
        'Chrome' => '/\b(Chrome|CriOS|Chromium)\//',
        'Safari' => '/\bSafari\//',
    ];

    /** Systems in matching order: Android also says "Linux", iOS also says "Mac OS X". */
    private const SYSTEMS = [
        'iPadOS' => '/\biPad\b/',
        'iOS' => '/\b(iPhone|iPod)\b/',
        'Android' => '/\bAndroid\b/',
        'ChromeOS' => '/\bCrOS\b/',
        'Windows' => '/\bWindows\b/',
        'macOS' => '/\bMacintosh\b|\bMac OS X\b/',
        'Linux' => '/\bLinux\b/',
    ];

    /** "Firefox on macOS", "Safari", "Android", or null when nothing is recognised. */
    public static function from(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        $browser = self::firstMatch(self::BROWSERS, $userAgent);
        $system = self::firstMatch(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => $browser.' on '.$system,
            default => $browser ?? $system,
        };
    }

    /** The label in the current language ("Firefox auf macOS"); "Unknown device" for none. */
    public static function display(?string $label): string
    {
        if (blank($label)) {
            return __('Unknown device');
        }

        [$browser, $system] = array_pad(explode(' on ', $label, 2), 2, null);

        return $system === null ? $browser : __(':browser on :system', ['browser' => $browser, 'system' => $system]);
    }

    /** @param  array<string, string>  $patterns */
    private static function firstMatch(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
