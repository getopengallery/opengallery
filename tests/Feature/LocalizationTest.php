<?php

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Extensions\Manifest;
use App\Extensions\ThemeManager;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\RichEditor\PhotoBlock;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Support\Locales;
use App\Support\SiteSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Js;
use Livewire\Livewire;

it('offers English and every language core ships a translation for', function () {
    expect(Locales::available())->toHaveKeys(['en', 'de'])
        ->and(Locales::available()['de'])->toBe('Deutsch');
});

it('saves the default language in the admin settings', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    Livewire::test(GeneralSettings::class)
        ->set('data.locale', 'de')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(SiteSettings::LOCALE))->toBe('de')
        ->and(Locales::default())->toBe('de');
});

it('rejects a default language core has no translation for', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    Livewire::test(GeneralSettings::class)
        ->set('data.locale', 'xx')
        ->call('save')
        ->assertHasErrors(['data.locale']);

    expect(Setting::get(SiteSettings::LOCALE))->toBeNull();
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
        ->assertSee('Profile Information');

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

it('lets people choose their language in the profile, or go back to the site default', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'de'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->locale)->toBe('de');

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => ''])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->locale)->toBeNull();
});

it('rejects a profile language core has no translation for', function () {
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'xx'])
        ->assertSessionHasErrors('locale');

    expect($user->refresh()->locale)->toBe('de');
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

it('shows a plugin\'s and a theme\'s own texts from their language files', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin, ['locale' => 'de']));

    $this->get(route('filament.admin.pages.plugins'))
        ->assertOk()
        ->assertSee('Identitätsanbieter')
        ->assertDontSee('identity-providers::plugin.name');

    $this->get(route('filament.admin.pages.themes.settings', ['theme' => 'default']))
        ->assertOk()
        ->assertSee('Farben')
        ->assertSee('Schwarz-Weiß');
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

it('labels admin search results in the reader\'s language', function () {
    app()->setLocale('de');
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $group = Group::factory()->create(['description' => 'Familie und Freunde']);

    expect(UserResource::getGlobalSearchResultDetails($user))->toBe(['E-Mail-Adresse' => 'ada@example.com'])
        ->and(GroupResource::getGlobalSearchResultDetails($group))->toBe(['Beschreibung' => 'Familie und Freunde']);
});

it('passes the photo field\'s count and remove label to the browser in the reader\'s language', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin, ['locale' => 'de']));

    $this->get(AlbumResource::getUrl('create'))
        ->assertOk()
        ->assertSee(Js::from(':count Fotos')->toHtml(), false)
        ->assertSee(Js::from(':title entfernen')->toHtml(), false);
});

it('shows an empty photo block in the page editor in the reader\'s language', function () {
    app()->setLocale('de');

    expect(PhotoBlock::toPreviewHtml([]))->toContain('Noch keine Fotos ausgewählt.');
});
