<?php

use App\Models\User;
use Illuminate\Support\Str;

it('sends guests to the login page', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

it('lists notifications in the reader\'s language, falls back to the stored title for a removed class, and marks the shown ones as read', function () {
    $grace = User::factory()->create(['locale' => 'de']);
    sharedCamp($grace);
    $grace->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Plugins\\Removed\\Notice',
        'data' => ['format' => 'filament', 'title' => 'A note from a removed plugin'],
    ]);

    $this->actingAs($grace)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Ada hat „Camp“ mit dir geteilt')
        ->assertSee('A note from a removed plugin');

    expect($grace->unreadNotifications()->count())->toBe(0);
});

it('hides the album title and link once access has ended', function () {
    [$ada, $album] = restrictedAlbumBy();
    $grace = User::factory()->create();
    shareWith($album, [$grace], $ada);

    $this->actingAs($grace)->get(route('notifications.index'))
        ->assertSee('Camp')
        ->assertSee(route('albums.show', $album->slug));

    $album->sharedUsers()->detach($grace);

    $this->get(route('notifications.index'))
        ->assertDontSee('Camp')
        ->assertDontSee(route('albums.show', $album->slug))
        ->assertSee('This album is no longer available to you');
});
