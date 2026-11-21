<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Locale;

/**
 * The languages OpenGallery speaks.
 *
 * English is the source language: every core string is written in English and
 * used as its own translation key, so English needs no file. Every other
 * language is available once core ships `lang/{locale}.json` for it (themes
 * and plugins only add to or override a language core already has).
 *
 * The language of a request: the logged-in user's choice (users.locale), else
 * the site default from Admin → Settings (config('gallery.default_locale');
 * config('app.locale') is the language of the current request).
 */
class Locales
{
    /** The language core strings are written in. */
    public const SOURCE = 'en';

    /** Native names for when PHP's intl extension is missing (common on shared hosting). */
    private const NAMES = [
        'ar' => 'العربية', 'bg' => 'Български', 'ca' => 'Català', 'cs' => 'Čeština', 'da' => 'Dansk',
        'de' => 'Deutsch', 'el' => 'Ελληνικά', 'en' => 'English', 'es' => 'Español', 'et' => 'Eesti',
        'fi' => 'Suomi', 'fr' => 'Français', 'he' => 'עברית', 'hr' => 'Hrvatski', 'hu' => 'Magyar',
        'it' => 'Italiano', 'ja' => '日本語', 'ko' => '한국어', 'lt' => 'Lietuvių', 'lv' => 'Latviešu',
        'nb' => 'Norsk bokmål', 'nl' => 'Nederlands', 'pl' => 'Polski', 'pt' => 'Português',
        'pt_BR' => 'Português (Brasil)', 'ro' => 'Română', 'ru' => 'Русский', 'sk' => 'Slovenčina',
        'sl' => 'Slovenščina', 'sr' => 'Српски', 'sv' => 'Svenska', 'tr' => 'Türkçe', 'uk' => 'Українська',
        'zh_CN' => '中文（简体）', 'zh_TW' => '中文（繁體）',
    ];

    /** @var array<string, string>|null */
    private static ?array $available = null;

    /**
     * Selectable languages, code => name in that language, sorted by name.
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $codes = collect(File::glob(lang_path('*.json')) ?: [])
            ->map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME))
            ->filter(fn (string $code) => preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $code) === 1)
            ->push(self::SOURCE)
            ->unique();

        return self::$available = $codes
            ->mapWithKeys(fn (string $code) => [$code => self::name($code)])
            ->sort(fn (string $a, string $b) => strcoll($a, $b))
            ->all();
    }

    public static function isAvailable(?string $code): bool
    {
        return is_string($code) && array_key_exists($code, self::available());
    }

    /** Name of a language in that language ("Deutsch"), falling back to its code. */
    public static function name(string $code): string
    {
        if (isset(self::NAMES[$code])) {
            return self::NAMES[$code];
        }

        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayName($code, $code);

            if ($name !== '' && $name !== $code) {
                return mb_convert_case(mb_substr($name, 0, 1), MB_CASE_TITLE).mb_substr($name, 1);
            }
        }

        return $code;
    }

    /** The site default chosen in Admin → Settings. */
    public static function default(): string
    {
        return (string) (config('gallery.default_locale') ?: config('app.locale'));
    }

    /** The language to use for this user (or a guest). */
    public static function for(?User $user): string
    {
        return self::isAvailable($user?->locale) ? $user->locale : self::default();
    }

    /** Forget the cached list (after language files were added in tests). */
    public static function flush(): void
    {
        self::$available = null;
    }
}
