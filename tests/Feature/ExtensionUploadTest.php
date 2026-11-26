<?php

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Extensions\ExtensionInstaller;
use App\Extensions\ExtensionInstallFailed;
use App\Extensions\PluginManager;
use App\Extensions\ThemeManager;
use App\Filament\Pages\Plugins;
use App\Filament\Pages\Themes;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->root = storage_path('framework/testing/extensions-'.uniqid());
    File::ensureDirectoryExists($this->root.'/themes');
    File::ensureDirectoryExists($this->root.'/plugins');
    File::copyDirectory(base_path('themes/default'), $this->root.'/themes/default');

    app()->instance(ThemeManager::class, new ThemeManager($this->root.'/themes', 'default'));
    app()->instance(PluginManager::class, new PluginManager(app(), $this->root.'/plugins'));

    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
});

afterEach(fn () => File::deleteDirectory($this->root));

it('installs an uploaded theme folder from the Themes page and logs it', function () {
    Livewire::test(Themes::class)
        ->callAction('upload', data: ['package' => extensionZip([
            'sunset/theme.json' => '{"name": "Sunset", "version": "1.2.0"}',
            'sunset/views/blocks/footer.blade.php' => '<footer>sunset</footer>',
            '__MACOSX/sunset/._theme.json' => 'junk',
        ])])
        ->assertHasNoActionErrors()
        ->assertRedirect(Themes::getUrl());

    $theme = app(ThemeManager::class)->all()->get('sunset');
    expect($theme->version)->toBe('1.2.0')
        ->and(file_get_contents($theme->path.'/views/blocks/footer.blade.php'))->toBe('<footer>sunset</footer>')
        ->and(app(ThemeManager::class)->active()->slug)->toBe('default')
        ->and(glob($this->root.'/themes/.*upload*'))->toBe([])
        ->and(ActivityEvent::where('type', ActivityType::ThemeInstalled)->sole()->subjectLabel())->toBe('Sunset');
});

it('installs an uploaded plugin switched off, named after the zip when its files are at the top', function () {
    Livewire::test(Plugins::class)
        ->callAction('upload', data: ['package' => extensionZip([
            'plugin.json' => '{"name": "Watermark", "provider": "Plugins\\\\Watermark\\\\Provider"}',
            'src/Provider.php' => '<?php',
        ], 'watermark (1).zip')])
        ->assertHasNoActionErrors();

    $plugins = app(PluginManager::class);
    expect($plugins->all()->has('watermark'))->toBeTrue()
        ->and($plugins->isEnabled('watermark'))->toBeFalse()
        ->and(is_file($this->root.'/plugins/watermark/src/Provider.php'))->toBeTrue()
        ->and(ActivityEvent::where('type', ActivityType::PluginInstalled)->exists())->toBeTrue();
});

it('replaces an installed extension only when asked to', function () {
    $installer = app(ExtensionInstaller::class);
    $zip = fn (string $version) => extensionZipPath(['sunset/theme.json' => '{"name": "Sunset", "version": "'.$version.'"}', 'sunset/assets/'.$version.'.css' => '']);

    $installer->installTheme($zip('1.0.0'), 'sunset.zip');

    expect(fn () => $installer->installTheme($zip('2.0.0'), 'sunset.zip'))
        ->toThrow(ExtensionInstallFailed::class, '"sunset" is already installed.');

    $installer->installTheme($zip('2.0.0'), 'sunset.zip', replace: true);

    expect(app(ThemeManager::class)->all()->get('sunset')->version)->toBe('2.0.0')
        ->and(is_file($this->root.'/themes/sunset/assets/1.0.0.css'))->toBeFalse()
        ->and(glob($this->root.'/themes/.*'))->toBe([$this->root.'/themes/.', $this->root.'/themes/..']);
});

it('refuses zips that would write outside their folder, contain links or lack a manifest', function (array $files, string $message) {
    expect(fn () => app(ExtensionInstaller::class)->installPlugin(extensionZipPath($files), 'evil.zip'))
        ->toThrow(ExtensionInstallFailed::class, $message);

    expect(File::directories($this->root.'/plugins'))->toBe([])
        ->and(file_exists($this->root.'/evil.php'))->toBeFalse();
})->with([
    'path traversal' => [['evil/plugin.json' => '{"name": "Evil"}', 'evil/../../evil.php' => '<?php'], 'paths outside its folder'],
    'symbolic link' => [['evil/plugin.json' => '{"name": "Evil"}', 'evil/passwd' => null], 'links'],
    'no manifest' => [['evil/readme.txt' => 'hi'], 'has no plugin.json'],
    'two folders' => [['a/plugin.json' => '{"name": "A"}', 'b/plugin.json' => '{"name": "B"}'], 'has no plugin.json'],
    'manifest without a name' => [['evil/plugin.json' => '{"version": "1.0.0"}'], 'needs at least a "name"'],
    'unusable folder name' => [['bad name!/plugin.json' => '{"name": "Bad"}'], 'cannot be used as a folder name'],
]);

it('reports an upload that is not a zip file without installing anything', function () {
    Livewire::test(Themes::class)
        ->callAction('upload', data: ['package' => UploadedFile::fake()->createWithContent('theme.zip', 'not a zip')])
        ->assertNotified('The theme was not installed');

    expect(app(ThemeManager::class)->all()->keys()->all())->toBe(['default']);
});

it('offers uploads only with the permission and while the server allows them', function () {
    $designer = User::factory()->create()->givePermissionTo(Permission::AccessAdmin->value, Permission::ManageThemes->value);
    $this->actingAs($designer);
    Livewire::test(Themes::class)->assertActionHidden('upload');

    $designer->givePermissionTo(Permission::InstallExtensions->value);
    Livewire::test(Themes::class)->assertActionVisible('upload');

    config(['gallery.extension_uploads' => false]);
    Livewire::test(Themes::class)->assertActionHidden('upload');
});
