<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Support\Favorites;
use App\Support\PhotoGridItems;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

describe('the heart', function () {
    it('likes a photo and unlikes it on the second call', function () {
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();

        $this->actingAs($user)->postJson(route('favorites.toggle', $photo))->assertOk()->assertExactJson(['favorite' => true]);
        expect($user->favoritePhotos()->pluck('photos.id')->all())->toBe([$photo->id]);

        $this->postJson(route('favorites.toggle', $photo))->assertOk()->assertExactJson(['favorite' => false]);
        expect($user->favoritePhotos()->count())->toBe(0);
    });

    it('is for logged-in people only', function () {
        $photo = favoritesPublicPhoto();

        $this->postJson(route('favorites.toggle', $photo))->assertUnauthorized();
        $this->get(route('favorites.index'))->assertRedirect(route('login'));
    });

    it('is off for everyone while the setting is off', function () {
        Setting::put(SiteSettings::FAVORITES_ENABLED, false);
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();
        $user->favoritePhotos()->attach($photo);

        $this->actingAs($user)->postJson(route('favorites.toggle', $photo))->assertNotFound();
        $this->get(route('favorites.index'))->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('favorites.index'));
        expect($user->favoritePhotos()->count())->toBe(1)
            ->and(Favorites::enabledFor($user))->toBeFalse();
    });

    it('refuses photos the person cannot see and stores nothing', function () {
        $user = User::factory()->create();
        $hidden = galleryPhoto(Album::factory()->visibility(Visibility::Private)->create());

        $this->actingAs($user)->postJson(route('favorites.toggle', $hidden))->assertNotFound();

        expect($user->favoritePhotos()->count())->toBe(0);
    });

    it('still lets people remove a heart from a photo they can no longer see', function () {
        $user = User::factory()->create();
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $photo = galleryPhoto($album);
        $user->favoritePhotos()->attach($photo);
        $album->update(['visibility' => Visibility::Private]);

        $this->actingAs($user)->postJson(route('favorites.toggle', $photo))->assertOk()->assertExactJson(['favorite' => false]);

        expect($user->favoritePhotos()->count())->toBe(0);
    });

    it('gives no rights to the photo', function () {
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();
        $media = $photo->original();

        $this->actingAs($user)->postJson(route('favorites.toggle', $photo))->assertOk();

        $this->get(route('media.show', ['media' => $media, 'download' => 1]))->assertNotFound();
        expect($user->can('update', $photo))->toBeFalse()
            ->and($user->can('delete', $photo))->toBeFalse()
            ->and($photo->albums()->count())->toBe(1);
    });
});

describe('the lightbox', function () {
    it('carries the heart and whether the photo is liked for logged-in people', function () {
        $user = User::factory()->create();
        $liked = favoritesPublicPhoto();
        $other = favoritesPublicPhoto();
        $user->favoritePhotos()->attach($liked);

        $items = $this->actingAs($user)
            ->getJson(route('albums.photos', $liked->albums()->first()))
            ->assertOk()
            ->json('items');

        expect($items[0]['favorite'])->toBe(['url' => route('favorites.toggle', $liked), 'active' => true])
            ->and(PhotoGridItems::from([$other])[0]['lightbox']['favorite'])->toBe(['url' => route('favorites.toggle', $other), 'active' => false]);
    });

    it('has no heart for guests or while the setting is off', function () {
        $photo = favoritesPublicPhoto();

        expect(PhotoGridItems::from([$photo])[0]['lightbox']['favorite'])->toBeNull();

        Setting::put(SiteSettings::FAVORITES_ENABLED, false);
        $this->actingAs(User::factory()->create());

        expect(PhotoGridItems::from([$photo])[0]['lightbox']['favorite'])->toBeNull();
    });
});

describe('the Favorites page', function () {
    it('shows the favourites, newest liked first', function () {
        $user = User::factory()->create();
        [$first, $second] = [favoritesPublicPhoto(), favoritesPublicPhoto()];
        $user->favoritePhotos()->attach($first, ['created_at' => now()->subDay()]);
        $user->favoritePhotos()->attach($second, ['created_at' => now()]);

        $this->actingAs($user)
            ->get(route('favorites.index'))
            ->assertOk()
            ->assertSeeInOrder([photoThumbUrl($second), photoThumbUrl($first)], false);
    });

    it('shows only the person\'s own favourites', function () {
        $user = User::factory()->create();
        [$mine, $theirs] = [favoritesPublicPhoto(), favoritesPublicPhoto()];
        $user->favoritePhotos()->attach($mine);
        User::factory()->create()->favoritePhotos()->attach($theirs);

        $this->actingAs($user)
            ->get(route('favorites.index'))
            ->assertOk()
            ->assertSee(photoThumbUrl($mine), false)
            ->assertDontSee(photoThumbUrl($theirs), false);
    });

    it('leaves out photos in the trash and brings them back on restore', function () {
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();
        $user->favoritePhotos()->attach($photo);

        $photo->delete();
        $this->actingAs($user)->get(route('favorites.index'))->assertOk()->assertDontSee(photoThumbUrl($photo), false);

        $photo->restore();
        $this->get(route('favorites.index'))->assertSee(photoThumbUrl($photo), false);
    });

    it('leaves out photos the person can no longer see', function () {
        $user = User::factory()->create();
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $photo = galleryPhoto($album);
        $user->favoritePhotos()->attach($photo);

        $album->update(['visibility' => Visibility::Private]);

        $this->actingAs($user)->get(route('favorites.index'))->assertOk()->assertDontSee(photoThumbUrl($photo), false);
        expect($user->favoritePhotos()->count())->toBe(1);
    });

    it('drops a photo from everyone\'s favourites when it is deleted for good', function () {
        $photo = favoritesPublicPhoto();
        $user = User::factory()->create();
        $user->favoritePhotos()->attach($photo);

        $photo->forceDelete();

        expect($user->favoritePhotos()->count())->toBe(0)
            ->and(DB::table('photo_favorites')->count())->toBe(0);
    });

    it('is paged', function () {
        $user = User::factory()->create();
        $photos = collect(range(1, 3))->map(fn () => favoritesPublicPhoto());
        $user->favoritePhotos()->attach($photos->pluck('id'));

        $page = Favorites::page($user, 2);

        expect($page->total())->toBe(3)
            ->and($page->items())->toHaveCount(2);
    });
});

describe('My albums', function () {
    it('shows a Favorites tile once the person has a favourite they can see', function () {
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee(route('favorites.index'));

        $user->favoritePhotos()->attach($photo);

        $this->get(route('dashboard'))->assertOk()->assertSee(route('favorites.index'))->assertSee('1 photo');
    });

    it('links the Favorites tile to the favorites page and counts the photos', function () {
        $user = User::factory()->create();
        $user->favoritePhotos()->attach([favoritesPublicPhoto()->id, favoritesPublicPhoto()->id]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('favorites.index').'"', false)
            ->assertSee('Favorites')
            ->assertSee('2 photos');
    });

    it('shows no tile when the only favourite is in the trash', function () {
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();
        $user->favoritePhotos()->attach($photo);
        $photo->delete();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee(route('favorites.index'));
    });
});
