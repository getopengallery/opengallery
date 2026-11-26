<?php

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;

it('shows other refusals in the site\'s look with a way back', function () {
    $this->actingAs(userWithRole(Role::Moderator))
        ->get(UserResource::getUrl())
        ->assertForbidden()
        ->assertSee("You don't have access to this page")
        ->assertSee(route('home'));
});

it('renders error pages from the theme', function () {
    useFixtureThemes('contact-sheet');

    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('contact-sheet not found');
});
