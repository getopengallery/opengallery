<?php

namespace App\Support\Updates;

use App\Models\Setting;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;

/**
 * Saves and restores OpenGallery's tables around an update, in portions that
 * fit a shared-hosting request. Needs no `mysqldump`.
 *
 * Only tables OpenGallery's migrations create are touched, because a database
 * on shared hosting may also hold other applications' tables.
 */
final class DatabaseBackup
{
    private const BATCH = 500;

    /** Recreated empty: their contents are rebuilt by themselves. */
    private const WITHOUT_ROWS = ['cache', 'cache_locks'];

    private Connection $db;

    public function __construct(private string $directory, ?string $connection = null)
    {
        $this->db = DB::connection($connection);
    }

    /**
     * Saves the next portion, until $deadline.
     *
     * @param  list<string>|null  $migrationPaths  folders with migrations (default: core and plugins)
     * @return bool whether the backup is complete
     */
    public function backup(float $deadline, ?array $migrationPaths = null): bool
    {
        $state = $this->state() ?? $this->begin($migrationPaths ?? self::migrationPaths());

        while ($state['index'] < count($state['tables'])) {
            $table = $state['tables'][$state['index']];

            if (in_array($this->withoutPrefix($table['name']), self::WITHOUT_ROWS, true)) {
                $state = $this->nextTable($state);

                continue;
            }

            $rows = $this->nextRows($table, $state);

            if ($rows !== []) {
                $this->append($table['file'], $rows);
                $state['written'] += count($rows);
                $state['offset'] += count($rows);

                if ($table['key'] !== null) {
                    $state['cursor'] = ((array) end($rows))[$table['key']];
                }
            }

            if (count($rows) < self::BATCH) {
                $this->assertComplete($table, $state['written']);
                $state = $this->nextTable($state);
            }

            $this->save($state);

            if (microtime(true) >= $deadline) {
                return $state['index'] >= count($state['tables']);
            }
        }

        $this->save($state);

        return true;
    }

    /**
     * Puts the saved tables back, until $deadline. Tables the failed update
     * created are removed if they appear in $newMigrationPaths.
     *
     * @param  list<string>  $newMigrationPaths
     * @return bool whether the restore is complete
     *
     * @throws UpdateFailed
     */
    public function restore(float $deadline, array $newMigrationPaths = []): bool
    {
        $state = $this->state();

        if ($state === null) {
            throw new UpdateFailed(__('The database backup is missing.'));
        }

        $state['restore'] ??= ['stage' => 'schema', 'index' => 0, 'position' => 0, 'restored' => 0];
        $this->db->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->prepareSession();

        try {
            if ($state['restore']['stage'] === 'schema') {
                $this->rebuildSchema($state, $newMigrationPaths);
                $state['restore']['stage'] = 'rows';
                $this->save($state);
            }

            while ($state['restore']['index'] < count($state['tables'])) {
                $finished = $this->restoreRows($state);
                $this->save($state);

                if (! $finished && microtime(true) >= $deadline) {
                    return false;
                }
            }
        } finally {
            $this->db->getSchemaBuilder()->enableForeignKeyConstraints();
        }

        Setting::forgetCached();

        return true;
    }

    /**
     * Tables OpenGallery's migrations create in the given folders.
     *
     * @param  list<string>  $paths
     * @return list<string> names including the connection's table prefix
     */
    public function ownedTables(array $paths): array
    {
        $names = ['migrations', ...array_values(array_filter((array) config('permission.table_names'), 'is_string'))];

        foreach ($paths as $path) {
            foreach (glob(rtrim($path, '/').'/*.php') ?: [] as $file) {
                $code = (string) file_get_contents($file);
                preg_match_all('/Schema::(?:connection\([^)]*\)->)?create\(\s*[\'"]([\w-]+)[\'"]/', $code, $created);
                preg_match_all('/Schema::(?:connection\([^)]*\)->)?rename\(\s*[\'"][\w-]+[\'"]\s*,\s*[\'"]([\w-]+)[\'"]/', $code, $renamed);
                $names = [...$names, ...$created[1], ...$renamed[1]];
            }
        }

        return array_values(array_unique(array_map(fn (string $name) => $this->db->getTablePrefix().$name, $names)));
    }

    /** @return list<string> core's migration folder and those of installed plugins */
    private static function migrationPaths(): array
    {
        return [database_path('migrations'), ...(glob(base_path('plugins/*/database/migrations'), GLOB_ONLYDIR) ?: [])];
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function begin(array $paths): array
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new UpdateFailed(__('The database backup could not be saved.'));
        }

        $existing = $this->existingTables();
        $tables = [];

        foreach (array_values(array_intersect($this->ownedTables($paths), $existing)) as $position => $name) {
            $tables[] = [
                'name' => $name,
                'file' => sprintf('%04d.jsonl.gz', $position),
                'schema' => $this->schemaOf($name),
                'key' => $this->singleKey($name),
                'order' => $this->orderColumns($name),
                'rows' => $this->db->table($this->withoutPrefix($name))->count(),
            ];
        }

        $state = ['tables' => $tables, 'index' => 0, 'cursor' => null, 'offset' => 0, 'written' => 0];
        $this->save($state);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  array<string, mixed>  $state
     * @return list<stdClass>
     */
    private function nextRows(array $table, array $state): array
    {
        $query = $this->db->table($this->withoutPrefix($table['name']))->limit(self::BATCH);

        if ($table['key'] !== null) {
            $query->orderBy($table['key']);

            if ($state['cursor'] !== null) {
                $query->where($table['key'], '>', $state['cursor']);
            }
        } else {
            foreach ($table['order'] as $column) {
                $query->orderBy($column);
            }

            $query->offset($state['offset']);
        }

        return $query->get()->all();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function nextTable(array $state): array
    {
        return [...$state, 'index' => $state['index'] + 1, 'cursor' => null, 'offset' => 0, 'written' => 0];
    }

    /**
     * @param  array<string, mixed>  $table
     *
     * @throws UpdateFailed when rows went missing
     */
    private function assertComplete(array $table, int $written): void
    {
        if ($written !== $table['rows']) {
            throw new UpdateFailed(__('The database backup could not be saved.'));
        }
    }

    /** @param  list<stdClass>  $rows */
    private function append(string $file, array $rows): void
    {
        $handle = @gzopen("{$this->directory}/{$file}", 'ab6');

        if ($handle === false) {
            throw new UpdateFailed(__('The database backup could not be saved.'));
        }

        try {
            foreach ($rows as $row) {
                $encoded = array_map(self::encodeValue(...), (array) $row);

                if (gzwrite($handle, json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n") === false) {
                    throw new UpdateFailed(__('The database backup could not be saved.'));
                }
            }
        } catch (JsonException) {
            throw new UpdateFailed(__('The database backup could not be saved.'));
        } finally {
            gzclose($handle);
        }
    }

    /** Text stays readable; bytes that are not valid UTF-8 are wrapped in base64. */
    private static function encodeValue(mixed $value): mixed
    {
        return is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? ['__base64' => base64_encode($value)] : $value;
    }

    private static function decodeValue(mixed $value): mixed
    {
        return is_array($value) ? base64_decode((string) $value['__base64'], true) : $value;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $newMigrationPaths
     */
    private function rebuildSchema(array $state, array $newMigrationPaths): void
    {
        $known = array_column($state['tables'], 'name');
        $added = array_intersect($this->ownedTables($newMigrationPaths), $this->existingTables());

        foreach (array_unique([...$known, ...$added]) as $name) {
            $this->db->statement('DROP TABLE IF EXISTS '.$this->quote($name));
        }

        foreach ($state['tables'] as $table) {
            foreach ($table['schema'] as $statement) {
                $this->db->statement($statement);
            }
        }
    }

    /**
     * Restores one portion of the current table.
     *
     * @param  array<string, mixed>  $state
     * @return bool whether the table is finished
     */
    private function restoreRows(array &$state): bool
    {
        $table = $state['tables'][$state['restore']['index']];
        $file = "{$this->directory}/{$table['file']}";

        if (in_array($this->withoutPrefix($table['name']), self::WITHOUT_ROWS, true) || ! is_file($file)) {
            $this->finishRestoredTable($state, $table);

            return true;
        }

        $handle = gzopen($file, 'rb');

        if ($handle === false) {
            throw new UpdateFailed(__('The database backup could not be read.'));
        }

        try {
            gzseek($handle, $state['restore']['position']);
            $rows = [];

            while (count($rows) < self::BATCH && ($line = gzgets($handle)) !== false) {
                $rows[] = array_map(self::decodeValue(...), json_decode($line, true, 8, JSON_THROW_ON_ERROR));
            }

            $position = gztell($handle);
            $isAtEnd = gzeof($handle) || count($rows) < self::BATCH;
        } catch (JsonException) {
            throw new UpdateFailed(__('The database backup could not be read.'));
        } finally {
            gzclose($handle);
        }

        if ($rows !== []) {
            $this->db->transaction(fn () => $this->db->table($this->withoutPrefix($table['name']))->insert($rows));
            $state['restore']['position'] = $position;
            $state['restore']['restored'] += count($rows);
        }

        if (! $isAtEnd) {
            return false;
        }

        $this->finishRestoredTable($state, $table);

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $table
     *
     * @throws UpdateFailed when rows went missing
     */
    private function finishRestoredTable(array &$state, array $table): void
    {
        $expected = in_array($this->withoutPrefix($table['name']), self::WITHOUT_ROWS, true) ? 0 : $table['rows'];

        if ($this->db->table($this->withoutPrefix($table['name']))->count() !== $expected) {
            throw new UpdateFailed(__('The database could not be restored.'));
        }

        $state['restore'] = [...$state['restore'], 'index' => $state['restore']['index'] + 1, 'position' => 0, 'restored' => 0];
    }

    /** Keeps ids of 0 and stored dates as they are while restoring on MySQL and MariaDB. */
    private function prepareSession(): void
    {
        if (in_array($this->db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->db->statement("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        }
    }

    /** @return list<string> */
    private function existingTables(): array
    {
        if ($this->db->getDriverName() === 'sqlite') {
            return array_column($this->db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name');
        }

        return array_map(
            fn (object $row): string => (string) array_values((array) $row)[0],
            $this->db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"),
        );
    }

    /** @return list<string> the statements that recreate $name with its indexes */
    private function schemaOf(string $name): array
    {
        if ($this->db->getDriverName() === 'sqlite') {
            return array_column($this->db->select(
                "SELECT sql FROM sqlite_master WHERE tbl_name = ? AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END",
                [$name],
            ), 'sql');
        }

        $row = (array) $this->db->selectOne('SHOW CREATE TABLE '.$this->quote($name));

        return [(string) array_values($row)[1]];
    }

    private function singleKey(string $name): ?string
    {
        foreach ($this->db->getSchemaBuilder()->getIndexes($this->withoutPrefix($name)) as $index) {
            if ($index['primary'] && count($index['columns']) === 1) {
                return $index['columns'][0];
            }
        }

        return null;
    }

    /** @return list<string> columns that give a stable order for reading in portions */
    private function orderColumns(string $name): array
    {
        $table = $this->withoutPrefix($name);

        foreach ($this->db->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['primary']) {
                return $index['columns'];
            }
        }

        return $this->db->getSchemaBuilder()->getColumnListing($table);
    }

    private function withoutPrefix(string $name): string
    {
        $prefix = $this->db->getTablePrefix();

        return $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
    }

    private function quote(string $name): string
    {
        return $this->db->getDriverName() === 'sqlite'
            ? '"'.str_replace('"', '""', $name).'"'
            : '`'.str_replace('`', '``', $name).'`';
    }

    /** @return array<string, mixed>|null */
    private function state(): ?array
    {
        $file = "{$this->directory}/backup.json";
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($state) ? $state : null;
    }

    /** @param  array<string, mixed>  $state */
    private function save(array $state): void
    {
        $file = "{$this->directory}/backup.json";

        if (@file_put_contents("{$file}.tmp", json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! @rename("{$file}.tmp", $file)) {
            throw new UpdateFailed(__('The database backup could not be saved.'));
        }
    }
}
