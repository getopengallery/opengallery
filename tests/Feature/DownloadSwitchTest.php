<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\AlbumAccess;
use App\Support\PhotoGridItems;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

/** A photo in a public album that allows downloads. */
function downloadablePhotoInPublicAlbum(): Photo
{
    return collaborationPhoto(Album::factory()->visibility(Visibility::Public)->create(['allow_downloads' => true]), null, 'Beach.jpg');
}

/** The lightbox item of $photo as listed in its album (where the album decides on downloads). @return array<string, mixed> */
function downloadLightboxItem(Photo $photo): array
{
    return PhotoGridItems::from($photo->albums()->first()->photos()->get())[0]['lightbox'];
}

describe('with the switch off', function () {
    it('keeps downloads from visitors even where the album allows them', function () {
        $photo = downloadablePhotoInPublicAlbum();
        $album = $photo->albums()->first();

        expect(SiteSettings::downloadsEnabled())->toBeFalse();
        $this->get(route('media.show', ['media' => $photo->original(), 'download' => 1]))->assertNotFound();
        $this->get(route('albums.download', $album))->assertNotFound();
        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertDontSee(route('albums.download', $album))
            ->assertDontSee('download=1');
        expect(downloadLightboxItem($photo)['download'])->toBeNull()
            ->and(AlbumAccess::canDownload(null, $photo))->toBeFalse();
    });

    it('still gives people who may edit the photo its original', function () {
        $photo = downloadablePhotoInPublicAlbum();
        $album = $photo->albums()->first();

        $this->actingAs($album->owner)
            ->get(route('media.show', ['media' => $photo->original(), 'download' => 1]))
            ->assertOk();
    });

    it('does not let a visitor with a favourite download either', function () {
        $photo = downloadablePhotoInPublicAlbum();
        $user = User::factory()->create();
        $user->favoritePhotos()->attach($photo);

        $this->actingAs($user)->get(route('media.show', ['media' => $photo->original(), 'download' => 1]))->assertNotFound();
    });

    it('hides the album setting for downloads', function () {
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
        $album = Album::factory()->visibility(Visibility::Public)->create();

        $this->actingAs($admin);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])->assertFormFieldHidden('allow_downloads');

        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])->assertFormFieldVisible('allow_downloads');
    });
});

describe('with the switch on', function () {
    it('offers downloads only where the album allows them', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $allowed = downloadablePhotoInPublicAlbum();
        $closed = collaborationPhoto(Album::factory()->visibility(Visibility::Public)->create());

        expect(downloadLightboxItem($allowed)['download'])->toBe(route('media.show', ['media' => $allowed->original(), 'download' => 1]))
            ->and(downloadLightboxItem($closed)['download'])->toBeNull();

        $this->get(route('albums.show', $allowed->albums()->first()))->assertSee(route('albums.download', $allowed->albums()->first()));
    });
});

describe('the update migration', function () {
    $migrate = fn () => (require database_path('migrations/2026_11_26_194554_keep_downloads_on_where_albums_allow_them.php'))->up();

    it('turns downloads on where an album already allows them', function () use ($migrate) {
        Album::factory()->create(['allow_downloads' => true]);
        Setting::where('key', SiteSettings::DOWNLOADS_ENABLED)->delete();

        $migrate();

        expect(SiteSettings::downloadsEnabled())->toBeTrue();
    });

    it('leaves downloads off when no album allows them', function () use ($migrate) {
        Album::factory()->create(['allow_downloads' => false]);

        $migrate();

        expect(SiteSettings::downloadsEnabled())->toBeFalse()
            ->and(Setting::where('key', SiteSettings::DOWNLOADS_ENABLED)->exists())->toBeFalse();
    });

    it('keeps a choice the admin already made', function () use ($migrate) {
        Album::factory()->create(['allow_downloads' => true]);
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, false);

        $migrate();

        expect(SiteSettings::downloadsEnabled())->toBeFalse();
    });
});
