<?php

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Extensions\PluginManager;
use App\Filament\Pages\Plugins;
use App\Models\ActivityEvent;
use Livewire\Livewire;
use Plugins\IdentityProviders\Filament\Settings as IdentityProviderSettings;

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin));

    it('lists the plugins, linking the settings of an enabled one, and filters and searches them', function () {
        $page = Livewire::test(Plugins::class);
        $table = $page->instance()->getTable();

        $page->assertSee(IdentityProviderSettings::getUrl())
            ->assertSee('Enabled in the server configuration');
        // A plugin that is off has no settings to open.
        expect($table->getRecordUrl($table->getLivewire()->getTableRecord('identity-providers')))->toBe(IdentityProviderSettings::getUrl())
            ->and($table->getRecordUrl($table->getLivewire()->getTableRecord('example')))->toBeNull();

        $page->filterTable('status', 'inactive')
            ->assertDontSee('Identity providers')
            ->assertSee('Example Plugin')
            ->resetTableFilters()
            ->searchTable('example')
            ->assertSee('Example Plugin')
            ->assertDontSee('Identity providers');
    });

    it('turns plugins on and off with the switch in their row, then reloads the list', function () {
        Livewire::test(Plugins::class)
            ->call('updateTableColumnState', 'active', 'example', true)
            ->assertRedirect(Plugins::getUrl());
        expect(app(PluginManager::class)->isEnabled('example'))->toBeTrue();

        Livewire::test(Plugins::class)->call('updateTableColumnState', 'active', 'example', false);
        expect(app(PluginManager::class)->isEnabled('example'))->toBeFalse();
    });

    it('locks the switch of a plugin enabled in the server configuration', function () {
        Livewire::test(Plugins::class)
            ->call('updateTableColumnState', 'active', 'identity-providers', false);
        expect(app(PluginManager::class)->isEnabled('identity-providers'))->toBeTrue();

        Livewire::test(Plugins::class)->call('toggle', 'identity-providers')->assertStatus(422);
    });
});

describe('uploading a plugin', function () {
    beforeEach(function () {
        adminUseExtensionSandbox();
        actingAsRole(Role::Admin);
    });

    afterEach(fn () => adminRemoveExtensionSandbox());

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
});

it('shows a plugin\'s own texts from its language files in the reader\'s language', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin, ['locale' => 'de']));

    $this->get(route('filament.admin.pages.plugins'))
        ->assertOk()
        ->assertSee('Identitätsanbieter')
        ->assertDontSee('identity-providers::plugin.name');
});
