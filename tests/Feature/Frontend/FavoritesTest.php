<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Support\Favorites;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

describe('a guest', function () {
    it('can neither like a photo nor open the Favorites page', function () {
        $photo = favoritesPublicPhoto();

        $this->postJson(route('favorites.toggle', $photo))->assertUnauthorized();
        $this->get(route('favorites.index'))->assertRedirect(route('login'));
    });
});

describe('the heart', function () {
    it('likes a photo and unlikes it on the second call', function () {
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();

        $this->actingAs($user)->postJson(route('favorites.toggle', $photo))->assertOk()->assertExactJson(['favorite' => true]);
        expect($user->favoritePhotos()->pluck('photos.id')->all())->toBe([$photo->id]);

        $this->postJson(route('favorites.toggle', $photo))->assertOk()->assertExactJson(['favorite' => false]);
        expect($user->favoritePhotos()->count())->toBe(0);
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

describe('the Favorites page', function () {
    it('shows only the person\'s own favourites, newest liked first', function () {
        $user = User::factory()->create();
        [$first, $second, $theirs] = [favoritesPublicPhoto(), favoritesPublicPhoto(), favoritesPublicPhoto()];
        $user->favoritePhotos()->attach($first, ['created_at' => now()->subDay()]);
        $user->favoritePhotos()->attach($second, ['created_at' => now()]);
        User::factory()->create()->favoritePhotos()->attach($theirs);

        $this->actingAs($user)
            ->get(route('favorites.index'))
            ->assertOk()
            ->assertSeeInOrder([photoThumbUrl($second), photoThumbUrl($first)], false)
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
