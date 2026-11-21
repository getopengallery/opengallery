<?php

use App\Enums\Role;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Album;
use App\Models\User;
use Livewire\Livewire;

it('shows dates as DD.MM.YYYY HH:MM in admin lists, in every language', function (?string $locale) {
    $this->actingAs(tap(User::factory()->create(['locale' => $locale]))->assignRole(Role::SuperAdmin->value));
    $member = User::factory()->create(['email_verified_at' => '2026-03-07 09:05:00']);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$member])
        ->assertSee('07.03.2026 09:05')
        ->assertDontSee('Mar 7, 2026');
})->with(['site default' => [null], 'German' => ['de']]);

it('shows when sharing ends in the same format', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::SuperAdmin->value));
    Album::factory()->create(['access_expires_at' => now()->addYear()->setDate(2027, 1, 2)->setTime(18, 30)]);

    Livewire::test(ListAlbums::class)
        ->assertSee('Shared until 02.01.2027 18:30');
});
