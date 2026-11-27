<?php

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\Manifest;
use App\Extensions\ThemeManager;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('offers English and every language core ships a translation for', function () {
    expect(Locales::available())->toHaveKeys(['en', 'de'])
        ->and(Locales::available()['de'])->toBe('Deutsch');
});

it('shows visitors the site in the default language', function () {
    useDefaultLocale('de');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<html lang="de">', false)
        ->assertSee('Passwort vergessen?');
});

it('shows a user the site and the admin in their own language, whatever the default', function () {
    useDefaultLocale('de');
    $admin = userWithRole(Role::SuperAdmin, ['locale' => 'en']);

    $this->actingAs($admin)->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('<html lang="en">', false)
        ->assertSee('Profile information');

    $this->actingAs($admin)->get(GeneralSettings::getUrl())
        ->assertOk()
        ->assertSee('Default language')
        ->assertDontSee('Standardsprache');
});

it('follows the default language for users who have not chosen one', function () {
    useDefaultLocale('de');

    $this->actingAs(userWithRole(Role::SuperAdmin))->get(GeneralSettings::getUrl())
        ->assertOk()
        ->assertSee('Standardsprache')
        ->assertSee('Benutzer &amp; Zugang', false);
});

it('ignores a stored language that is no longer available', function () {
    $user = User::factory()->create(['locale' => 'xx']);

    expect($user->preferredLocale())->toBe('en');
});

it('sends e-mails in the recipient\'s language', function () {
    Notification::fake();
    $german = User::factory()->create(['locale' => 'de']);
    $english = User::factory()->create();

    $this->post(route('password.email'), ['email' => $german->email]);
    $this->post(route('password.email'), ['email' => $english->email]);

    Notification::assertSentTo($german, ResetPassword::class, fn ($notification, $channels, $notifiable, $locale) => $locale === 'de');
    Notification::assertSentTo($english, ResetPassword::class, fn ($notification, $channels, $notifiable, $locale) => $locale === 'en');
});

it('records changes in English and shows them in the reader\'s language', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin, ['locale' => 'de']));
    $album = Album::factory()->create(['title' => 'Sommer', 'visibility' => Visibility::Private]);
    $this->travel(1)->hour(); // past the window in which changes merge into the "created" entry

    $album->update(['visibility' => Visibility::Public]);

    $event = ActivityEvent::where('type', ActivityType::Updated)->where('subject_id', $album->id)->sole();
    expect($event->properties['changes']['Visibility'])->toMatchArray(['from' => 'Private (owner only)', 'to' => 'Public', 'translate' => true]);

    app()->setLocale('de');

    expect($event->summary())->toBe($event->user->name.' änderte Album Sommer')
        ->and($event->details())->toBe(['Sichtbarkeit: Privat (nur Eigentümer) → Öffentlich']);
});

it('shows plain manifest texts as written, without matching them against core translations', function () {
    app()->setLocale('de');

    expect(Manifest::text('Albums'))->toBe('Albums')
        ->and(Manifest::text('theme-default::settings.sections.albums.label'))->toBe('Alben');
});

it('does not let a theme\'s own language files shadow core texts', function () {
    app(ThemeManager::class)->boot();
    app()->setLocale('en');

    // The Default theme ships lang/en/settings.php for its own "theme-default::settings" texts.
    expect(__('Settings'))->toBe('Settings')
        ->and(__('theme-default::settings.palettes.moss'))->toBe('Moss');
});
