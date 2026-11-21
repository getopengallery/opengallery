<?php

namespace App\Support\Setup;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The database step of the setup wizard: checks the details someone typed
 * in, saves them to `.env`, switches the running request over and creates
 * the tables and built-in roles (the same as `php artisan gallery:install`).
 */
class DatabaseSetup
{
    /** Where the SQLite option keeps its database; tests point it elsewhere. */
    public static ?string $sqlitePath = null;

    private const CHECK_CONNECTION = 'setup_check';

    public static function sqlitePath(): string
    {
        return self::$sqlitePath ?? database_path('database.sqlite');
    }

    /**
     * Tries to connect. SQLite creates its database file first.
     *
     * @param  array{connection: string, host?: ?string, port?: int|string|null, database?: ?string, username?: ?string, password?: ?string}  $input
     * @return string|null why it failed, or null when the connection works
     */
    public static function check(array $input): ?string
    {
        if ($input['connection'] === 'sqlite' && ! is_file(self::sqlitePath()) && @touch(self::sqlitePath()) === false) {
            return __('The database file :file could not be created.', ['file' => self::sqlitePath()]);
        }

        $config = self::connectionConfig($input);
        // A wrong host should not keep the page loading for minutes.
        $config['options'] = [PDO::ATTR_TIMEOUT => 10] + (array) ($config['options'] ?? []);
        config(['database.connections.'.self::CHECK_CONNECTION => $config]);

        try {
            DB::connection(self::CHECK_CONNECTION)->getPdo();

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        } finally {
            DB::purge(self::CHECK_CONNECTION);
        }
    }

    /**
     * Saves the connection (and the site address) to `.env`, uses it for the
     * rest of this request and runs the migrations and the roles seeder.
     *
     * @param  array{connection: string, host?: ?string, port?: int|string|null, database?: ?string, username?: ?string, password?: ?string}  $input
     *
     * @throws RuntimeException when `.env` cannot be written or a migration fails
     */
    public static function apply(array $input, string $url): void
    {
        $connection = $input['connection'];
        $sqlite = $connection === 'sqlite';

        EnvironmentFile::set([
            'APP_URL' => $url,
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $sqlite ? null : (string) $input['host'],
            'DB_PORT' => $sqlite ? null : (string) $input['port'],
            // The default SQLite file needs no entry; the path would break when the folder moves.
            'DB_DATABASE' => $sqlite ? (self::sqlitePath() === database_path('database.sqlite') ? null : self::sqlitePath()) : (string) $input['database'],
            'DB_USERNAME' => $sqlite ? null : (string) $input['username'],
            'DB_PASSWORD' => $sqlite ? null : (string) ($input['password'] ?? ''),
        ]);

        config([
            'app.url' => $url,
            'database.connections.'.$connection => self::connectionConfig($input),
            'database.default' => $connection,
        ]);
        DB::purge($connection);
        DB::setDefaultConnection($connection);

        foreach ([
            ['migrate', ['--force' => true]],
            ['db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]],
        ] as [$command, $parameters]) {
            if (Artisan::call($command, $parameters) !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: $command);
            }
        }
    }

    /**
     * @param  array{connection: string, host?: ?string, port?: int|string|null, database?: ?string, username?: ?string, password?: ?string}  $input
     * @return array<string, mixed>
     */
    private static function connectionConfig(array $input): array
    {
        $connection = $input['connection'];
        $base = (array) config("database.connections.{$connection}");

        if ($connection === 'sqlite') {
            return [...$base, 'url' => null, 'database' => self::sqlitePath()];
        }

        return [
            ...$base,
            'url' => null,
            'host' => (string) $input['host'],
            'port' => (string) $input['port'],
            'database' => (string) $input['database'],
            'username' => (string) $input['username'],
            'password' => (string) ($input['password'] ?? ''),
        ];
    }
}
