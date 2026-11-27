<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Documentation;
use App\Filament\Pages\Plugins;
use App\Filament\Pages\Themes;
use App\Filament\Pages\ThemeSettings;
use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Album;
use App\Models\Group;
use App\Models\Page;
use App\Models\Role as RoleModel;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Plugins\IdentityProviders\Filament\Settings as IdentityProviderSettings;

it('ships a translation for every core text in every language', function (string $file) {
    $translations = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_diff_key(coreTranslationKeys(), $translations);

    expect($missing)->toBe([], basename($file).' lacks: '.implode(' | ', array_keys($missing)));
})->with(fn () => glob(dirname(__DIR__, 3).'/lang/*.json'));

it('keeps the :placeholders of every core text in its translations', function (string $file) {
    $translations = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);

    $broken = collect($translations)
        ->filter(fn (string $translation, string $key) => placeholders($translation) !== placeholders($key))
        ->keys()
        ->all();

    expect($broken)->toBe([]);
})->with(fn () => glob(dirname(__DIR__, 3).'/lang/*.json'));

it('ships every language of a theme or plugin with the same keys as its English texts', function (string $dir) {
    $english = collect(glob($dir.'/en/*.php'))->mapWithKeys(fn (string $file) => [basename($file) => Arr::dot(require $file)]);

    foreach (glob($dir.'/*', GLOB_ONLYDIR) as $localeDir) {
        foreach ($english as $group => $lines) {
            $translated = is_file($localeDir.'/'.$group) ? Arr::dot(require $localeDir.'/'.$group) : [];

            expect(array_keys($translated))->toEqualCanonicalizing(array_keys($lines), basename($localeDir).'/'.$group);

            foreach ($lines as $key => $line) {
                expect(placeholders($translated[$key]))->toBe(placeholders($line), basename($localeDir).'/'.$group.': '.$key);
            }
        }
    }
})->with(fn () => array_values(array_filter(glob(dirname(__DIR__, 3).'/{themes,plugins}/*/lang', GLOB_BRACE) ?: [], fn ($dir) => is_dir($dir.'/en'))));

it('renders the admin and the site in German without untranslated texts', function () {
    $missing = [];
    $german = germanTexts();
    // Developer-facing: Spatie's exception for a permission check on a permission that is not seeded, caught by the gate.
    $internal = ['There is no permission named `:permission` for guard `:guard`.'];
    app('translator')->handleMissingKeysUsing(function (string $key, array $replace, ?string $locale) use (&$missing, $german, $internal) {
        // Filament translates explicit labels once more (translateLabel()); those are German already.
        if ($locale === 'de' && $key !== '' && ! isset($german[$key]) && ! in_array($key, $internal, true)) {
            $missing[$key] = true;
        }

        return $key;
    });

    $admin = tap(User::factory()->create(['locale' => 'de']))->assignRole(Role::SuperAdmin->value);
    $album = Album::factory()->for($admin, 'owner')->create();
    $page = Page::factory()->create();
    $group = Group::factory()->create();
    $license = packLicense('CC0 1.0');

    $urls = [
        '/admin',
        AlbumResource::getUrl(), AlbumResource::getUrl('create'), AlbumResource::getUrl('edit', ['record' => $album]),
        PhotoResource::getUrl(),
        PageResource::getUrl(), PageResource::getUrl('create'), PageResource::getUrl('edit', ['record' => $page]),
        UserResource::getUrl(), UserResource::getUrl('create'), UserResource::getUrl('edit', ['record' => $admin]),
        GroupResource::getUrl(), GroupResource::getUrl('edit', ['record' => $group]),
        LicenseResource::getUrl(), LicenseResource::getUrl('create'), LicenseResource::getUrl('edit', ['record' => $license]),
        RoleResource::getUrl(), RoleResource::getUrl('edit', ['record' => RoleModel::findByName(Role::Uploader->value)]),
        ActivityEventResource::getUrl(),
        ...array_map(fn (string $page): string => $page::getUrl(), SettingsCluster::getClusteredComponents()),
        Themes::getUrl(), ThemeSettings::getUrl(['theme' => 'default']), Plugins::getUrl(),
        IdentityProviderSettings::getUrl(), Documentation::getUrl(),
        route('home'), route('albums.show', $album), route('dashboard'), route('profile.edit'),
    ];

    foreach ($urls as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
    auth()->logout();
    $this->get(route('login'))->assertOk();
    $this->get(route('register'))->assertOk();

    expect(array_keys($missing))->toBe([]);
});
