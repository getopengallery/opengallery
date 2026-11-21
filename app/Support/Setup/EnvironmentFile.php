<?php

namespace App\Support\Setup;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Reads and changes the server configuration file (`.env`) for the setup
 * wizard. Other lines, comments and their order are kept; a missing file is
 * started from `.env.example`.
 */
class EnvironmentFile
{
    public static function path(): string
    {
        return app()->environmentFilePath();
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** Whether the file can be written (or created, when it does not exist yet). */
    public static function isWritable(): bool
    {
        $path = self::path();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    /**
     * Sets the given keys: a string replaces the value (or adds the line),
     * null comments the line out.
     *
     * @param  array<string, string|null>  $values
     *
     * @throws RuntimeException when the file cannot be written
     */
    public static function set(array $values): void
    {
        $lines = preg_split('/\r\n|\n|\r/', self::contents());

        foreach ($values as $key => $value) {
            $line = $value === null ? null : $key.'='.self::quote($value);
            $found = false;

            foreach ($lines as $index => $existing) {
                if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/', $existing)) {
                    $lines[$index] = $line ?? '# '.$existing;
                    $found = true;
                }
            }

            if (! $found && $line !== null) {
                $lines[] = $line;
            }
        }

        $contents = rtrim(implode("\n", $lines))."\n";

        if (@file_put_contents(self::path(), $contents, LOCK_EX) === false) {
            throw new RuntimeException(__('The server configuration file :file could not be written.', ['file' => self::path()]));
        }

        // A cached configuration would keep the old values.
        File::delete(app()->getCachedConfigPath());
    }

    /** The current file, or `.env.example` as the starting point. */
    private static function contents(): string
    {
        foreach ([self::path(), base_path('.env.example')] as $file) {
            if (is_file($file) && is_readable($file)) {
                return (string) file_get_contents($file);
            }
        }

        return '';
    }

    /** Plain values stay as they are; anything else is double-quoted with \, " and $ escaped. */
    private static function quote(string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('Values in the server configuration file cannot span several lines.');
        }

        if (preg_match('/^[A-Za-z0-9_.:\/@+=-]*$/', $value)) {
            return $value;
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }
}
