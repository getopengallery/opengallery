<?php

use App\Support\Updates\DatabaseBackup;
use App\Support\Updates\UpdateFailed;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->folder = storage_path('framework/testing/backup-'.Str::lower(Str::random(10)));
    File::ensureDirectoryExists("{$this->folder}/migrations");
    config(['database.connections.backup_test' => backupConnectionConfig($this->folder)]);
    DB::purge('backup_test');
    dropBackupTestTables();

    $schema = Schema::connection('backup_test');
    $schema->create('bk_albums', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->json('options')->nullable();
    });
    $schema->create('bk_photos', function (Blueprint $table) {
        $table->id();
        $table->foreignId('album_id')->constrained('bk_albums');
        $table->string('name')->index();
        $table->binary('thumbnail')->nullable();
        $table->timestamp('taken_at')->nullable();
    });
    $schema->create('bk_pivot', function (Blueprint $table) {
        $table->unsignedBigInteger('left_id');
        $table->unsignedBigInteger('right_id');
        $table->primary(['left_id', 'right_id']);
    });
    $schema->create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->text('value');
    });
    $schema->create('bk_other_app', function (Blueprint $table) {
        $table->id();
        $table->string('value');
    });

    File::put("{$this->folder}/migrations/2026_01_01_create.php", <<<'PHP'
        <?php
        Schema::create('bk_albums', fn ($table) => null);
        Schema::create('bk_photos', fn ($table) => null);
        Schema::create('bk_pivot', fn ($table) => null);
        Schema::create('cache', fn ($table) => null);
        PHP);

    $this->db = DB::connection('backup_test');
    $this->paths = ["{$this->folder}/migrations"];
    $this->backup = new DatabaseBackup("{$this->folder}/dump", 'backup_test');
});

afterEach(function () {
    dropBackupTestTables();
    DB::purge('backup_test');
    File::deleteDirectory($this->folder);
});

it('restores every value exactly, across several portions', function () {
    $binary = random_bytes(64)."\xFF\x00\xFE";
    $this->db->table('bk_albums')->insert(['id' => 7, 'title' => 'Überraschung 📷 "quoted"', 'options' => '{"a":[1,2]}']);
    $this->db->table('bk_photos')->insert(['id' => 3, 'album_id' => 7, 'name' => 'with binary', 'thumbnail' => $binary, 'taken_at' => '2026-03-04 05:06:07']);
    $this->db->table('bk_photos')->insert(collect(range(10, 1210))->map(fn (int $id) => ['id' => $id, 'album_id' => 7, 'name' => "photo {$id}", 'thumbnail' => null, 'taken_at' => null])->all());
    $this->db->table('bk_pivot')->insert(collect(range(1, 1100))->map(fn (int $id) => ['left_id' => $id % 5, 'right_id' => $id])->all());
    $albums = $this->db->table('bk_albums')->get();
    $photos = $this->db->table('bk_photos')->orderBy('id')->get();
    $pivot = $this->db->table('bk_pivot')->orderBy('left_id')->orderBy('right_id')->get();
    runBackup($this->backup, $this->paths);

    $this->db->table('bk_photos')->delete();
    $this->db->table('bk_pivot')->delete();
    $this->db->table('bk_albums')->update(['title' => 'changed by a failed update']);
    runRestore($this->backup);

    expect($this->db->table('bk_albums')->get()->toArray())->toEqual($albums->toArray())
        ->and($this->db->table('bk_photos')->orderBy('id')->get()->toArray())->toEqual($photos->toArray())
        ->and($this->db->table('bk_pivot')->orderBy('left_id')->orderBy('right_id')->get()->toArray())->toEqual($pivot->toArray());
});

it('puts back columns a failed update changed, with indexes and foreign keys', function () {
    $this->db->table('bk_albums')->insert(['id' => 1, 'title' => 'Kept']);
    runBackup($this->backup, $this->paths);

    $schema = Schema::connection('backup_test');
    $schema->table('bk_albums', fn (Blueprint $table) => $table->string('added_by_update')->nullable());
    runRestore($this->backup);

    expect($schema->hasColumn('bk_albums', 'added_by_update'))->toBeFalse()
        ->and($schema->hasIndex('bk_photos', ['name']))->toBeTrue()
        ->and(collect($schema->getForeignKeys('bk_photos'))->pluck('foreign_table')->all())->toBe(['bk_albums']);
    expect(fn () => $this->db->table('bk_photos')->insert(['album_id' => 999, 'name' => 'no such album']))
        ->toThrow(Exception::class);
});

it('removes tables the failed update created', function () {
    runBackup($this->backup, $this->paths);
    Schema::connection('backup_test')->create('bk_added', fn (Blueprint $table) => $table->id());
    File::ensureDirectoryExists("{$this->folder}/new");
    File::put("{$this->folder}/new/2026_02_01_add.php", "<?php Schema::create('bk_added', fn (\$table) => null);");

    runRestore($this->backup, ["{$this->folder}/new"]);

    expect(Schema::connection('backup_test')->hasTable('bk_added'))->toBeFalse();
});

it('never touches tables of another application in the same database', function () {
    $this->db->table('bk_other_app')->insert(['id' => 1, 'value' => 'before']);
    runBackup($this->backup, $this->paths);
    $this->db->table('bk_other_app')->update(['value' => 'changed meanwhile']);

    runRestore($this->backup, $this->paths);

    expect($this->db->table('bk_other_app')->value('value'))->toBe('changed meanwhile');
});

it('recreates the cache table empty', function () {
    $this->db->table('cache')->insert(['key' => 'old', 'value' => 'stale']);
    runBackup($this->backup, $this->paths);

    runRestore($this->backup);

    expect(Schema::connection('backup_test')->hasTable('cache'))->toBeTrue()
        ->and($this->db->table('cache')->count())->toBe(0);
});

it('refuses to restore without a backup', function () {
    $this->backup->restore(microtime(true) + 1);
})->throws(UpdateFailed::class, 'missing');

it('fails when a restored table does not hold every saved row', function () {
    $this->db->table('bk_albums')->insert([['id' => 1, 'title' => 'One'], ['id' => 2, 'title' => 'Two']]);
    runBackup($this->backup, $this->paths);
    $state = json_decode(File::get("{$this->folder}/dump/backup.json"), true);
    File::delete(collect($state['tables'])->where('name', 'bk_albums')->map(fn (array $table) => "{$this->folder}/dump/{$table['file']}")->first());

    runRestore($this->backup);
})->throws(UpdateFailed::class, 'could not be restored');
