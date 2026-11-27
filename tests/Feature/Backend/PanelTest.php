<?php

use App\Enums\CollaboratorRole;
use App\Enums\Role;
use App\Extensions\ThemeManager;
use App\Filament\Clusters\Settings\Pages\AccessSettings;
use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Filament\Clusters\Settings\Pages\AppSettings;
use App\Filament\Clusters\Settings\Pages\BackgroundJobSettings;
use App\Filament\Clusters\Settings\Pages\EmailSettings;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Clusters\Settings\Pages\LicenseSettings;
use App\Filament\Clusters\Settings\Pages\PhotoSettings;
use App\Filament\Clusters\Settings\Pages\SearchEngineSettings;
use App\Filament\Clusters\Settings\Pages\StorageSettings;
use App\Filament\Clusters\Settings\Pages\TagSettings;
use App\Filament\Clusters\Settings\Pages\UpdateSettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Documentation;
use App\Filament\Pages\Plugins;
use App\Filament\Pages\Themes;
use App\Filament\Pages\ThemeSettings;
use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Livewire\DatabaseNotifications;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Plugins\IdentityProviders\Filament\Settings as IdentityProviderSettings;

beforeEach(function () {
    $this->versionFile = tempnam(sys_get_temp_dir(), 'og-version-');
    File::put($this->versionFile, '1.0.0');
    AppVersion::useFile($this->versionFile);
});

afterEach(function () {
    AppVersion::useFile(null);
    File::delete($this->versionFile);
});

describe('who may enter the admin', function () {
    it('sends guests from the admin panel to the shared site login', function () {
        $this->get('/admin')->assertRedirect(route('login'));
    });

    it('restricts the admin panel to staff', function () {
        $this->actingAs(userWithRole(Role::User))->get('/admin')->assertForbidden();

        $this->actingAs(userWithRole())->get('/admin')->assertOk();
    });

    it('renders every admin page for an administrator', function () {
        $admin = actingAsRole();
        $album = Album::factory()->create();

        foreach (['/admin', '/admin/albums', '/admin/albums/create', "/admin/albums/{$album->slug}/edit",
            '/admin/users', '/admin/users/create', "/admin/users/{$admin->id}/edit",
            '/admin/groups', '/admin/roles', '/admin/themes', '/admin/plugins', GeneralSettings::getUrl()] as $url) {
            $this->get($url)->assertOk();
        }
    });

    it('hides user, role and extension management from moderators', function () {
        actingAsRole(Role::Moderator);

        $this->get('/admin/albums')->assertOk();
        foreach (['/admin/users', '/admin/roles', '/admin/groups', '/admin/themes', '/admin/plugins'] as $url) {
            $this->get($url)->assertForbidden();
        }
    });

    it('keeps every settings page closed to staff who may not manage settings', function (string $page) {
        actingAsRole(Role::Moderator);

        $this->get($page::getUrl())->assertForbidden();
    })->with([
        'settings entry' => SettingsCluster::class,
        'general' => GeneralSettings::class,
        'app' => AppSettings::class,
        'search engines' => SearchEngineSettings::class,
        'access' => AccessSettings::class,
        'photos' => PhotoSettings::class,
        'storage' => StorageSettings::class,
        'tags' => TagSettings::class,
        'licenses' => LicenseSettings::class,
        'e-mail' => EmailSettings::class,
        'activity log' => ActivityLogSettings::class,
        'background jobs' => BackgroundJobSettings::class,
        'updates' => UpdateSettings::class,
        'theme' => ThemeSettings::class,
        'identity providers plugin' => IdentityProviderSettings::class,
    ]);

    it('keeps the update settings closed to an uploader and the tag settings to a user with only admin access', function () {
        $this->actingAs(userWithRole(Role::Uploader))->get(UpdateSettings::getUrl())->assertForbidden();

        $this->actingAs(adminAccessUser());
        Livewire::test(TagSettings::class)->assertForbidden();
    });
});

describe('a member with a role on an album', function () {
    it('takes them from the admin home to the albums list', function () {
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Contributor));

        $this->get('/admin')->assertRedirect(AlbumResource::getUrl());
    });

    it('opens the admin only while they hold a role', function () {
        $album = Album::factory()->create();
        $member = User::factory()->create();
        $this->actingAs($member)->get(AlbumResource::getUrl())->assertForbidden();

        $album->collaborators()->attach($member, ['role' => CollaboratorRole::Editor->value]);
        $this->get(AlbumResource::getUrl())->assertOk();

        $album->collaborators()->detach($member->id);
        $this->get(AlbumResource::getUrl())->assertForbidden();
    });

    it('keeps every other admin section closed', function (Closure $url) {
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        $this->get($url())->assertForbidden();
    })->with([
        'media' => [fn () => PhotoResource::getUrl()],
        'documentation' => [fn () => Documentation::getUrl()],
        'users' => [fn () => UserResource::getUrl()],
        'groups' => [fn () => GroupResource::getUrl()],
        'roles' => [fn () => RoleResource::getUrl()],
        'pages' => [fn () => PageResource::getUrl()],
        'activity' => [fn () => ActivityEventResource::getUrl()],
        'settings' => [fn () => GeneralSettings::getUrl()],
        'themes' => [fn () => Themes::getUrl()],
        'plugins' => [fn () => Plugins::getUrl()],
    ]);

    it('sees only the albums section and no search', function () {
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        $this->get(AlbumResource::getUrl())
            ->assertOk()
            ->assertSee(AlbumResource::getUrl(), false)
            ->assertDontSee(PhotoResource::getUrl(), false)
            ->assertDontSee(Documentation::getUrl(), false)
            ->assertDontSeeLivewire('filament.livewire.global-search');
    });
});

describe('the menu', function () {
    it('lists the settings topics directly and opens the first one from the settings entry', function () {
        actingAsRole();

        expect(adminMenu())->toMatchArray([
            'Settings' => ['General', 'App', 'Search engines', 'Access', 'Photos', 'Storage', 'Tags', 'Licenses', 'E-mail'],
            'System' => ['Statistics', 'Activity log', 'Background jobs', 'Updates'],
        ]);

        $this->get(SettingsCluster::getUrl())->assertRedirect(GeneralSettings::getUrl());
        $this->get(GeneralSettings::getUrl())->assertOk()->assertDontSee('fi-page-has-sub-navigation');
    });

    it('changes admin pages without a reload but leaves the admin with a full page load', function () {
        actingAsRole();

        $html = $this->get('/admin')->assertOk()->getContent();
        preg_match_all('/<a\b[^>]*>/', $html, $anchors);
        $anchors = collect($anchors[0]);

        $albums = $anchors->first(fn (string $tag): bool => str_contains($tag, 'href="'.AlbumResource::getUrl().'"'));
        $viewSite = $anchors->first(fn (string $tag): bool => str_contains($tag, 'href="'.route('home').'"'));

        expect($albums)->not->toBeNull()->toContain('wire:navigate');
        expect($viewSite)->not->toBeNull()->not->toContain('wire:navigate');
    });
});

describe('the top bar', function () {
    it('shows the theme colours, corners, light/dark switch and account menu', function () {
        app(ThemeManager::class)->saveOptions('default', ['palette' => 'moss', 'radius' => '0px']);
        actingAsRole(attributes: ['name' => 'Ada Lovelace']);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('--og-primary: 63 90 60;', false)
            ->assertSee('--og-radius: 0px;', false)
            ->assertSee('--radius-lg: var(--og-radius, 6px);', false)
            ->assertSee("\$dispatch('theme-changed'", false)
            ->assertSee('og-account-trigger', false)
            ->assertSee('Ada Lovelace')
            ->assertSee(route('profile.edit'), false)
            ->assertSee(route('filament.admin.auth.logout'), false)
            ->assertDontSee('fi-theme-switcher', false)
            ->assertDontSee('fi-user-menu', false);
    });

    it('shows the version and the update link only to people who manage settings', function (Role $role, bool $visible) {
        backendOfferUpdate('1.1.0');
        actingAsRole($role);

        $response = $this->get('/admin')->assertOk();

        $visible ? $response->assertSee('Version 1.0.0')->assertSee('Update to 1.1.0') : $response->assertDontSee('Version 1.0.0')->assertDontSee('Update to 1.1.0');
    })->with([
        'administrator' => [Role::Admin, true],
        'uploader' => [Role::Uploader, false],
    ]);

    it('links the version to the updates page only while update checks are on', function (bool $checksOn) {
        backendOfferUpdate('1.1.0');
        Setting::put(SiteSettings::UPDATE_CHECKS, $checksOn);
        actingAsRole();

        $response = $this->get('/admin')->assertOk()->assertSee('Version 1.0.0');

        $checksOn
            ? $response->assertSeeHtml('href="'.UpdateSettings::getUrl().'" class="og-link">Version 1.0.0</a>')
            : $response->assertDontSeeHtml('class="og-link">Version 1.0.0</a>');
    })->with(['checks on' => true, 'checks off' => false]);

    it('drops the update link once the new version is installed', function () {
        backendOfferUpdate('1.1.0');
        File::put($this->versionFile, '1.1.0');
        actingAsRole();

        $this->get('/admin')->assertOk()->assertDontSee('Update to 1.1.0');
    });
});

describe('notifications in the top bar', function () {
    it('shows them with the gallery\'s date format', function () {
        $this->travelTo('2026-11-21 10:30:00');
        $ada = actingAsRole();
        sharedCamp($ada);

        Livewire::test(DatabaseNotifications::class)
            ->set('isOpen', true)
            ->assertSee('Ada shared')
            ->assertSee('21.11.2026 10:30')
            ->assertSee('Open');
    });

    it('hides the notice of a deleted album', function () {
        [$ada, $album] = restrictedAlbumBy();
        $admin = actingAsRole();
        shareWith($album, [$admin], $ada);
        $album->delete();

        Livewire::test(DatabaseNotifications::class)
            ->set('isOpen', true)
            ->assertDontSee('Camp')
            ->assertSee('This album is no longer available to you');
    });
});
