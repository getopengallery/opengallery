<?php

namespace App\Support\Setup;

/**
 * What the server needs before the setup wizard can continue: PHP version,
 * extensions, writable folders and the built frontend that ships with the
 * release zip.
 */
class Requirements
{
    public const PHP_VERSION = '8.3.0';

    /** Extensions OpenGallery needs (beyond what PHP always has). */
    private const EXTENSIONS = ['ctype', 'dom', 'fileinfo', 'filter', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'gd', 'exif', 'intl', 'zip'];

    /** Folders the web server must be able to write, relative to the project. */
    private const WRITABLE = ['storage', 'storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'];

    /**
     * Every check: label, passed, required (an optional one only informs).
     *
     * @return list<array{label: string, passed: bool, required: bool, hint: string|null}>
     */
    public static function checks(): array
    {
        $checks = [[
            'label' => __('PHP :version or newer (this server: :current)', ['version' => '8.3', 'current' => PHP_VERSION]),
            'passed' => version_compare(PHP_VERSION, self::PHP_VERSION, '>='),
            'required' => true,
            'hint' => null,
        ]];

        foreach (self::EXTENSIONS as $extension) {
            $checks[] = [
                'label' => __('PHP extension :name', ['name' => $extension]),
                'passed' => extension_loaded($extension),
                'required' => true,
                'hint' => null,
            ];
        }

        $checks[] = [
            'label' => __('A database driver (MySQL/MariaDB or SQLite)'),
            'passed' => self::databaseDrivers() !== [],
            'required' => true,
            'hint' => __('Needs the PHP extension pdo_mysql or pdo_sqlite.'),
        ];

        $checks[] = [
            'label' => __('PHP extension :name', ['name' => 'imagick']),
            'passed' => extension_loaded('imagick'),
            'required' => false,
            'hint' => __('Optional. Needed for HEIC photos from phones.'),
        ];

        foreach (self::WRITABLE as $folder) {
            $checks[] = [
                'label' => __('Folder :folder is writable', ['folder' => $folder]),
                'passed' => is_dir(base_path($folder)) && is_writable(base_path($folder)),
                'required' => true,
                'hint' => null,
            ];
        }

        $checks[] = [
            'label' => __('Server configuration file (.env) can be written'),
            'passed' => EnvironmentFile::isWritable(),
            'required' => true,
            'hint' => __('The wizard saves the database details there. Make the project folder (or an existing .env file) writable for the web server.'),
        ];

        $checks[] = [
            'label' => __('Built styles and scripts are present'),
            'passed' => self::assetsAreBuilt(),
            'required' => true,
            'hint' => __('Upload the release zip, not the "Source code" download.'),
        ];

        return $checks;
    }

    public static function passed(): bool
    {
        return collect(self::checks())->every(fn (array $check) => $check['passed'] || ! $check['required']);
    }

    /**
     * Database connections this server can use (Laravel connection names).
     *
     * @return list<string>
     */
    public static function databaseDrivers(): array
    {
        return array_values(array_filter([
            extension_loaded('pdo_mysql') ? 'mysql' : null,
            extension_loaded('pdo_mysql') ? 'mariadb' : null,
            extension_loaded('pdo_sqlite') ? 'sqlite' : null,
        ]));
    }

    /** The release ships public/build; a development server uses public/hot instead. */
    public static function assetsAreBuilt(): bool
    {
        return is_file(public_path('build/manifest.json')) || is_file(public_path('hot'));
    }
}
