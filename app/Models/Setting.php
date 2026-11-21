<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runtime key/value settings (active theme, enabled plugins, site name...).
 * Reads are cached forever and flushed on write. Within one request (or
 * queued job) the cached values are read from the cache store only once
 * (Cache::memo()); every read after that comes from memory.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    private const CACHE_KEY = 'gallery.settings';

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetCached());
        static::deleted(fn () => self::forgetCached());
    }

    /** Drops the cached values (in memory and in the cache $store, default: the configured one). */
    public static function forgetCached(?string $store = null): void
    {
        Cache::memo($store)->forget(self::CACHE_KEY);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::cachedValues()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Loaded during boot (themes/plugins), so it must survive a missing
     * table, e.g. before the first `php artisan migrate`.
     *
     * @return array<string, mixed>
     */
    private static function cachedValues(): array
    {
        try {
            return Cache::memo()->rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
        } catch (Throwable) {
            return [];
        }
    }
}
