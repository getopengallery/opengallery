<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\ThemeManager;
use App\Filament\Resources\Albums\AlbumResource;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;

describe('a guest', function () {
    it('sees only the public albums in the list', function (Visibility $visibility, bool $listed) {
        $album = Album::factory()->visibility($visibility)->create();

        $listed
            ? $this->get(route('home'))->assertSee($album->title)
            : $this->get(route('home'))->assertDontSee($album->title);
    })->with([
        'public' => [Visibility::Public, true],
        'unlisted' => [Visibility::Unlisted, false],
        'registered only' => [Visibility::Registered, false],
        'restricted' => [Visibility::Restricted, false],
        'private' => [Visibility::Private, false],
    ]);

    it('gets the site description and the plain summary card in the link preview, escaped', function () {
        Setting::put(SiteSettings::DESCRIPTION, 'Photos by "us" <script>alert(1)</script>');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<meta name="description" content="Photos by &quot;us&quot; &lt;script&gt;alert(1)&lt;/script&gt;">', false)
            ->assertSee('<meta name="twitter:card" content="summary">', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    });

    it('sees the albums below the landing page when the theme asks for it', function () {
        Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp']);
        themeOptions(['landing_heading' => 'Welcome to troop 7', 'landing_layout' => 'cover']);

        $this->get(route('home'))->assertSee('Summer camp')->assertDontSee('Welcome to troop 7');

        themeOptions(['landing_on_home' => 'guests', 'landing_heading' => 'Welcome to troop 7', 'landing_layout' => 'cover']);

        $this->get(route('home'))
            ->assertSeeInOrder(['Welcome to troop 7', 'View albums', 'id="albums"', 'Summer camp'], false)
            ->assertDontSee('The albums here are private.');
        $this->actingAs(User::factory()->create())->get(route('home'))
            ->assertSee('Summer camp')
            ->assertDontSee('Welcome to troop 7');

        themeOptions(['landing_on_home' => 'everyone', 'landing_heading' => 'Welcome to troop 7']);

        $this->get(route('home'))->assertSeeInOrder(['Welcome to troop 7', 'Summer camp']);
    });
});

describe('a signed-in person without albums to see', function () {
    it('is offered a New album button only when they may create albums', function (Role $role, bool $offered) {
        $response = $this->actingAs(userWithRole($role))->get(route('home'))->assertOk();

        $offered
            ? $response->assertSee(AlbumResource::getUrl('create'))
            : $response->assertDontSee(AlbumResource::getUrl('create'));
    })->with([
        'uploader' => [Role::Uploader, true],
        'member' => [Role::User, false],
    ]);
});

describe('with public albums turned off', function () {
    beforeEach(fn () => applySiteSettings([SiteSettings::PUBLIC_ALBUMS => false]));

    it('shows guests a landing page instead of albums', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        app(ThemeManager::class)->saveOptions('default', ['landing_heading' => 'Welcome to the Smiths', 'landing_text' => 'Ask Anna for an account.']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Welcome to the Smiths')
            ->assertSee('Ask Anna for an account.')
            ->assertDontSee($album->title);

        $this->get(route('albums.show', $album))->assertNotFound();
    });

    it('can send guests straight to the login page', function () {
        applySiteSettings([SiteSettings::GUEST_HOME => 'login']);

        $this->get(route('home'))->assertRedirect(route('login'));
    });

    it('still shows public albums to logged-in users', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $this->actingAs(userWithRole(Role::User));

        $this->get(route('home'))->assertSee($album->title);
        $this->get(route('albums.show', $album))->assertOk();
    });
});
