<?php

namespace App\Support\Setup;

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Whether this copy of OpenGallery has been set up, and the state the web
 * setup wizard (`/setup`, App\Http\Controllers\SetupController) needs
 * before a database exists.
 *
 * Installed means the marker file `storage/app/installed` exists. It is
 * written when the wizard or `php artisan gallery:install` finishes. Copies
 * set up before the wizard existed (a `.env` with an app key and a database
 * with a super administrator) get the marker on their first request.
 * `config('gallery.installed')` (GALLERY_INSTALLED) overrides the detection;
 * the tests set it to true.
 */
class Installation
{
    private const MARKER = 'installed';

    /** Written when the wizard creates the app key, so its own `.env` does not count as a finished installation. */
    private const IN_PROGRESS = 'setup-in-progress';

    private static ?bool $installed = null;

    /** Folder of the marker files; tests point it elsewhere. */
    private static ?string $directory = null;

    /** The cache store from the configuration, replaced by "file" while the wizard runs. */
    private static ?string $configuredCacheStore = null;

    public static function isInstalled(): bool
    {
        $configured = config('gallery.installed');

        if ($configured !== null && $configured !== '') {
            return (bool) $configured;
        }

        return self::$installed ??= self::detect();
    }

    /** Marks the installation as finished; from now on `/setup` is closed. */
    public static function markInstalled(): void
    {
        self::write(self::MARKER, now()->toIso8601String());
        @unlink(self::path(self::IN_PROGRESS));
        self::$installed = true;

        // Settings were cached in the wizard's file store; the configured store starts fresh.
        foreach (array_unique(array_filter([self::$configuredCacheStore, config('cache.default')])) as $store) {
            try {
                Setting::forgetCached($store);
            } catch (Throwable) {
                // A store that cannot be reached has nothing cached either.
            }
        }
    }

    /**
     * Prepares a request while the wizard runs: sessions, cache and rate
     * limits in files (there may be no database yet) and an app key, written
     * to `.env` straight away so the session survives the next request.
     * Called at the start of the register phase; uses no Eloquent.
     */
    public static function prepare(Application $app): void
    {
        self::$configuredCacheStore ??= (string) config('cache.default');

        config([
            'session.driver' => 'file',
            'cache.default' => 'file',
            'cache.limiter' => 'file',
            'queue.default' => 'sync',
        ]);

        // In case something already resolved the configured store.
        foreach (['cache.store', RateLimiter::class] as $service) {
            $app->forgetInstance($service);
        }

        try {
            self::begin();
        } catch (RuntimeException) {
            // Nothing can be saved: the requirements check on the first page says why.
        }

        if (filled(config('app.key'))) {
            return;
        }

        $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));

        // Only with the marker in place: a key in .env without it would look like a finished installation.
        if (is_file(self::path(self::IN_PROGRESS))) {
            try {
                EnvironmentFile::set(['APP_KEY' => $key, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
            } catch (RuntimeException) {
                // As above.
            }
        }

        config(['app.key' => $key]);
        $app->forgetInstance('encrypter');
    }

    /**
     * Notes that the wizard is running, so neither the `.env` it writes nor
     * the administrator it creates count as a finished installation.
     *
     * @throws RuntimeException when storage/app cannot be written
     */
    public static function begin(): void
    {
        if (! is_file(self::path(self::IN_PROGRESS))) {
            self::write(self::IN_PROGRESS, now()->toIso8601String());
        }
    }

    /** Whether the database is reachable, migrated and has the built-in roles. */
    public static function databaseIsReady(): bool
    {
        try {
            return Schema::hasTable('settings')
                && Schema::hasTable(config('permission.table_names.roles'))
                && DB::table(config('permission.table_names.roles'))->where('name', Role::SuperAdmin->value)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether a super administrator exists. Uses the query builder, so it works before Eloquent is booted. */
    public static function hasSuperAdmin(): bool
    {
        $tables = config('permission.table_names');

        return DB::table($tables['model_has_roles'])
            ->join($tables['roles'], $tables['roles'].'.id', '=', $tables['model_has_roles'].'.role_id')
            ->where($tables['roles'].'.name', Role::SuperAdmin->value)
            ->where($tables['model_has_roles'].'.model_type', (new User)->getMorphClass())
            ->exists();
    }

    /**
     * Creates the first super administrator (wizard and `gallery:install`).
     * Their e-mail address counts as confirmed.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public static function createSuperAdmin(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(Role::SuperAdmin->value);

        return $user;
    }

    /** Points the marker files at another folder (tests); null restores storage/app. */
    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory;
        self::flush();
    }

    /** Forgets the remembered answer of isInstalled(). */
    public static function flush(): void
    {
        self::$installed = null;
    }

    private static function detect(): bool
    {
        if (is_file(self::path(self::MARKER))) {
            return true;
        }

        // A fresh upload, or a .env the wizard started.
        if (blank(config('app.key')) || is_file(self::path(self::IN_PROGRESS))) {
            return false;
        }

        // A .env made by hand: installed when its database already has a super administrator.
        try {
            if (! Schema::hasTable(config('permission.table_names.model_has_roles'))) {
                return false;
            }

            $installed = self::hasSuperAdmin();
        } catch (Throwable) {
            // The database cannot be reached; keep the wizard closed rather than let anyone rewrite .env.
            return true;
        }

        if ($installed) {
            try {
                self::write(self::MARKER, now()->toIso8601String());
            } catch (RuntimeException) {
                // Checked again on the next request.
            }
        }

        return $installed;
    }

    private static function path(string $file): string
    {
        return (self::$directory ?? storage_path('app')).DIRECTORY_SEPARATOR.$file;
    }

    /** @throws RuntimeException when the file cannot be written */
    private static function write(string $file, string $contents): void
    {
        if (@file_put_contents(self::path($file), $contents) === false) {
            // Plain text: this can run in the register phase, before translations are set up.
            throw new RuntimeException('The file '.self::path($file).' could not be written.');
        }
    }
}
