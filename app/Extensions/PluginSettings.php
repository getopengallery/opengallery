<?php

namespace App\Extensions;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Settings storage for plugins: one JSON document per plugin in the
 * `settings` table (key "plugin.{slug}"). Use encrypt()/decrypt() for
 * secrets such as OAuth client secrets.
 *
 *   PluginSettings::get('identity-providers', 'providers', [])
 *   PluginSettings::put('identity-providers', 'providers', $list)
 */
class PluginSettings
{
    /** @return array<string, mixed> */
    public static function all(string $slug): array
    {
        return (array) Setting::get(self::key($slug), []);
    }

    public static function get(string $slug, string $key, mixed $default = null): mixed
    {
        return data_get(self::all($slug), $key, $default);
    }

    public static function put(string $slug, string $key, mixed $value): void
    {
        $settings = self::all($slug);
        data_set($settings, $key, $value);

        Setting::put(self::key($slug), $settings);
    }

    public static function encrypt(?string $secret): ?string
    {
        return $secret === null || $secret === '' ? null : Crypt::encryptString($secret);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            return null;
        }
    }

    private static function key(string $slug): string
    {
        return 'plugin.'.$slug;
    }
}
