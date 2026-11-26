<?php

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Extensions\ThemeManager;
use App\Filament\Pages\Themes;
use App\Filament\Pages\ThemeSettings;
use App\Models\ActivityEvent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

describe('with the installed themes', function () {
    it('switches to another theme with the Activate button in its row and opens a theme\'s settings from its row', function () {
        actingAsRole(Role::Admin);

        $page = Livewire::test(Themes::class);
        $table = $page->instance()->getTable();
        expect($table->getRecordUrl($page->instance()->getTableRecord('default')))->toBe(ThemeSettings::getUrl(['theme' => 'default']));

        // The active theme is marked instead of offering to activate it again.
        $page->assertActionHidden(TestAction::make('activate')->table('default'))
            ->assertActionVisible(TestAction::make('active')->table('default'))
            ->assertActionDisabled(TestAction::make('active')->table('default'))
            ->assertActionVisible(TestAction::make('activate')->table('scrapbook'))
            ->assertActionHidden(TestAction::make('active')->table('scrapbook'));

        // The theme in use is listed first.
        expect(array_key_first($page->instance()->getTableRecords()->all()))->toBe('default');

        $page->mountAction(TestAction::make('activate')->table('scrapbook'))
            ->assertMountedActionModalSee(['Activate Scrapbook?', 'Visitors see the new look right away.']);
        expect(app(ThemeManager::class)->active()->slug)->toBe('default');

        $page->callMountedAction()->assertRedirect(Themes::getUrl());
        expect(app(ThemeManager::class)->active()->slug)->toBe('scrapbook');
    });
});

describe('uploading a theme', function () {
    beforeEach(function () {
        adminUseExtensionSandbox();
        actingAsRole(Role::Admin);
    });

    afterEach(fn () => adminRemoveExtensionSandbox());

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
});
