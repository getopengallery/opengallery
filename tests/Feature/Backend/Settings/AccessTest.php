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

describe('guests', function () {
    it('shows the defaults: sharing by e-mail on, access for 30 days', function () {
        Livewire::test(AccessSettings::class)
            ->assertFormSet(['album_guests' => true, 'album_guest_days' => 30]);
    });

    it('saves and loads whether guests are allowed and for how many days', function () {
        Livewire::test(AccessSettings::class)
            ->fillForm(['album_guests' => true, 'album_guest_days' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Setting::get(SiteSettings::ALBUM_GUESTS))->toBeTrue()
            ->and(SiteSettings::albumGuestDays())->toBe(0);

        Livewire::test(AccessSettings::class)
            ->assertFormSet(['album_guests' => true, 'album_guest_days' => 0])
            ->fillForm(['album_guest_days' => 7])
            ->call('save');

        expect(SiteSettings::albumGuestDays())->toBe(7);

        Livewire::test(AccessSettings::class)
            ->fillForm(['album_guests' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(SiteSettings::albumGuestsEnabled())->toBeFalse()
            ->and(SiteSettings::albumGuestDays())->toBe(7);
    });

    it('rejects a number of days that is negative, too large or not a number', function (mixed $days) {
        Livewire::test(AccessSettings::class)
            ->fillForm(['album_guests' => true, 'album_guest_days' => $days])
            ->call('save')
            ->assertHasFormErrors(['album_guest_days']);
    })->with(['negative' => -1, 'too large' => 4000, 'text' => 'soon']);
});
