<?php

use App\Enums\Role as BuiltInRole;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\SearchEngines;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission as PermissionModel;

beforeEach(fn () => Storage::fake('gallery'));

it('keeps downloads on where an album allowed them before the switch existed, and respects an earlier choice', function (bool $albumAllows, ?bool $chosen, bool $expected, bool $stored) {
    Album::factory()->create(['allow_downloads' => $albumAllows]);
    $chosen === null ? Setting::where('key', SiteSettings::DOWNLOADS_ENABLED)->delete() : Setting::put(SiteSettings::DOWNLOADS_ENABLED, $chosen);

    (require database_path('migrations/2026_11_26_194554_keep_downloads_on_where_albums_allow_them.php'))->up();

    expect(SiteSettings::downloadsEnabled())->toBe($expected)
        ->and(Setting::where('key', SiteSettings::DOWNLOADS_ENABLED)->exists())->toBe($stored);
})->with([
    'an album allows downloads' => [true, null, true, true],
    'no album allows downloads' => [false, null, false, false],
    'the admin already chose off' => [true, false, false, true],
]);

it('moves existing photos into the album they were in when upgrading', function () {
    $migration = require database_path('migrations/2026_11_14_000000_create_album_photo_table.php');
    $album = Album::factory()->create();
    $photo = galleryPhoto($album, name: 'Lake.jpg', width: 300, height: 200);

    $migration->down();
    expect(DB::table('photos')->where('id', $photo->id)->value('album_id'))->toBe($album->id);

    $migration->up();
    expect($photo->albums()->pluck('albums.id')->all())->toBe([$album->id]);
});

it('moves other people\'s photos out of a former site-wide system album when upgrading', function () {
    [$anna, $ben] = [User::factory()->create(), User::factory()->create()];
    $shared = Album::inbox($anna); // the old site-wide album, owned by whoever needed it first
    $annas = Photo::factory()->inAlbum($shared)->create(['uploader_id' => $anna->id]);
    $bens = Photo::factory()->inAlbum($shared)->create(['uploader_id' => $ben->id]);
    $shared->updateQuietly(['cover_photo_id' => $bens->id]);

    (require database_path('migrations/2026_11_14_215133_give_everyone_their_own_system_albums.php'))->splitSystemAlbums();

    $bensInbox = Album::where('owner_id', $ben->id)->where('system_album', Album::INBOX)->sole();

    expect($annas->fresh()->albums->modelKeys())->toBe([$shared->id])
        ->and($bens->fresh()->albums->modelKeys())->toBe([$bensInbox->id])
        ->and($shared->fresh()->cover_photo_id)->toBe($annas->id)
        ->and($bensInbox->cover_photo_id)->toBe($bens->id)
        ->and($bensInbox->tree_path)->not->toBeNull()
        ->and(Album::inbox($ben)->id)->toBe($bensInbox->id);
});

it('clears IP addresses and user agents that older versions stored', function () {
    DB::table('sessions')->insert([
        'id' => 'old-session',
        'ip_address' => '203.0.113.7',
        'user_agent' => 'TestBrowser/1.0',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    (require database_path('migrations/2026_11_14_213203_clear_ip_addresses_and_user_agents_from_sessions.php'))->up();

    expect(DB::table('sessions')->sole())
        ->ip_address->toBeNull()
        ->user_agent->toBeNull();
});

it('removes the former comment permissions from existing installations', function () {
    $legacy = collect(['comment', 'moderate comments'])->map(fn (string $name) => PermissionModel::findOrCreate($name));
    Role::findByName(BuiltInRole::User->value)->givePermissionTo($legacy);

    (require database_path('migrations/2026_11_14_224213_remove_unused_tags_favorites_and_comment_permissions.php'))->up();

    expect(PermissionModel::whereIn('name', ['comment', 'moderate comments'])->exists())->toBeFalse()
        ->and(roleGrants(BuiltInRole::User))->toBe([]);
});

it('removes the old static robots.txt on upgrade, unless it was changed', function () {
    $public = sys_get_temp_dir().'/og-public-'.uniqid();
    mkdir($public);

    file_put_contents($public.'/robots.txt', SearchEngines::LEGACY_ROBOTS_FILE);
    expect(SearchEngines::removeLegacyRobotsFile($public))->toBeFalse()
        ->and(file_exists($public.'/robots.txt'))->toBeFalse();

    file_put_contents($public.'/robots.txt', "User-agent: *\nDisallow: /private\n");
    expect(SearchEngines::removeLegacyRobotsFile($public))->toBeTrue()
        ->and(file_exists($public.'/robots.txt'))->toBeTrue();

    unlink($public.'/robots.txt');
    rmdir($public);
});

it('gives every existing account unlimited storage when upgrading, but not accounts made after', function () {
    $migration = require database_path('migrations/2026_11_27_135241_add_storage_quota_to_users_table.php');
    $existing = User::factory()->create();

    $migration->down();
    $migration->up();
    $new = User::factory()->create();

    expect(DB::table('users')->where('id', $existing->id)->value('storage_unlimited'))->toBe(1)
        ->and($existing->fresh()->storage_unlimited)->toBeTrue()
        ->and($existing->fresh()->storage_quota_bytes)->toBeNull()
        ->and($new->fresh()->storage_unlimited)->toBeFalse()
        ->and($new->fresh()->storage_quota_bytes)->toBeNull();
});
