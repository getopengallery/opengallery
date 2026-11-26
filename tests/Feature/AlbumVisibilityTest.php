<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('shows public albums to guests', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();

    $this->get(route('albums.show', $album))->assertOk()->assertSee($album->title);
    $this->get(route('home'))->assertSee($album->title);
});

it('hides non-public albums from guests', function (Visibility $visibility) {
    $album = Album::factory()->visibility($visibility)->create();

    $this->get(route('albums.show', $album))->assertNotFound();
    $this->get(route('home'))->assertDontSee($album->title);
})->with([Visibility::Unlisted, Visibility::Registered, Visibility::Restricted, Visibility::Private]);

it('shows registered-only albums to any logged-in user', function () {
    $album = Album::factory()->visibility(Visibility::Registered)->create();

    $this->actingAs(userWithRole(Role::User))->get(route('albums.show', $album))->assertOk();
});

it('shows restricted albums only to shared users and group members', function () {
    $album = Album::factory()->visibility(Visibility::Restricted)->create();
    [$direct, $viaGroup, $outsider] = [userWithRole(Role::User), userWithRole(Role::User), userWithRole(Role::User)];

    $album->sharedUsers()->attach($direct);
    $group = Group::factory()->create();
    $group->users()->attach($viaGroup);
    $album->sharedGroups()->attach($group);

    $this->actingAs($direct)->get(route('albums.show', $album))->assertOk();
    $this->actingAs($viaGroup)->get(route('albums.show', $album))->assertOk();
    $this->actingAs($outsider)->get(route('albums.show', $album))->assertForbidden();

    expect(Album::listableFor($viaGroup)->pluck('id'))->toContain($album->id)
        ->and(Album::listableFor($outsider)->pluck('id'))->not->toContain($album->id);
});

it('shows private albums only to the owner and staff', function () {
    $owner = userWithRole(Role::User);
    $album = Album::factory()->visibility(Visibility::Private)->for($owner, 'owner')->create();
    $moderator = tap(User::factory()->create())->assignRole(Role::Moderator->value);

    $this->actingAs($owner)->get(route('albums.show', $album))->assertOk();
    $this->actingAs($moderator)->get(route('albums.show', $album))->assertOk();
    $this->actingAs(userWithRole(Role::User))->get(route('albums.show', $album))->assertForbidden();
});

it('opens unlisted albums through the share link without listing them', function () {
    $album = Album::factory()->visibility(Visibility::Unlisted)->create();

    expect($album->share_token)->not->toBeNull();

    $this->get(route('albums.shared', $album->share_token))->assertOk()->assertSee($album->title);
    $this->get(route('albums.show', $album))->assertOk();
    $this->get(route('home'))->assertDontSee($album->title);
});

it('drops the share token when an album stops being unlisted', function () {
    $album = Album::factory()->visibility(Visibility::Unlisted)->create();
    $token = $album->share_token;

    $album->update(['visibility' => Visibility::Private]);

    expect($album->fresh()->share_token)->toBeNull();
    $this->get(route('albums.shared', $token))->assertNotFound();
});

describe('albums shared with someone', function () {
    it('checks any number of albums with two queries per person', function () {
        $user = userWithRole(Role::User);
        $group = Group::factory()->create();
        $group->users()->attach($user);
        $albums = Album::factory()->count(3)->visibility(Visibility::Restricted)->create();
        $albums[0]->sharedUsers()->attach($user);
        $albums[1]->sharedGroups()->attach($group);
        DB::enableQueryLog();

        $shared = $albums->map(fn (Album $album) => $album->isSharedWith($user))->all();

        expect($shared)->toBe([true, true, false]);
        expect(DB::getQueryLog())->toHaveCount(2);
    });

    it('keeps the shares of different people apart', function () {
        [$invited, $other] = [userWithRole(Role::User), userWithRole(Role::User)];
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedUsers()->attach($invited);
        $album->isSharedWith($invited);

        $sharedWithOther = $album->isSharedWith($other);

        expect($sharedWithOther)->toBeFalse();
    });

    it('stops sharing at once when the share is removed during the request', function () {
        $user = userWithRole(Role::User);
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedUsers()->attach($user);
        $album->isSharedWith($user);

        $album->sharedUsers()->detach($user);

        expect($album->isSharedWith($user))->toBeFalse();
    });

    it('shares at once when someone joins a sharing group during the request', function () {
        $user = userWithRole(Role::User);
        $group = Group::factory()->create();
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedGroups()->attach($group);
        $album->isSharedWith($user);

        $group->users()->attach($user);

        expect($album->isSharedWith($user))->toBeTrue();
    });
});
