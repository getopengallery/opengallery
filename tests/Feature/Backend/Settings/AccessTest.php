<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\AccessSettings;
use App\Models\Setting;
use App\Support\SiteSettings;
use Livewire\Livewire;

beforeEach(fn () => actingAsRole(Role::Admin));

it('saves who may register and see public albums, and leaves the settings of other pages alone', function () {
    applySiteSettings([SiteSettings::NAME => 'Scout Troop 7']);

    Livewire::test(AccessSettings::class)
        ->set('data.registration', false)
        ->set('data.public_albums', false)
        ->set('data.guest_home', 'login')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(SiteSettings::REGISTRATION))->toBeFalse()
        ->and(Setting::get(SiteSettings::PUBLIC_ALBUMS))->toBeFalse()
        ->and(Setting::get(SiteSettings::GUEST_HOME))->toBe('login')
        ->and(Setting::get(SiteSettings::NAME))->toBe('Scout Troop 7');
});

it('keeps the guest start page when it is hidden in the form', function () {
    applySiteSettings([SiteSettings::GUEST_HOME => 'login']);

    Livewire::test(AccessSettings::class)->set('data.public_albums', true)->call('save');

    expect(Setting::get(SiteSettings::GUEST_HOME))->toBe('login');
});

it('turns a sign-up option on', function (string $field, Closure $isOn) {
    Livewire::test(AccessSettings::class)
        ->fillForm(['registration' => true, $field => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($isOn())->toBeTrue();
})->with([
    'approval' => ['require_approval', fn () => SiteSettings::requiresApproval()],
    'notices about new accounts' => ['notify_registrations', fn () => SiteSettings::notifiesAboutRegistrations()],
]);
