<?php

use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\GlobalSearch\AdminSearchProvider;
use App\Filament\Pages\Documentation as DocumentationPage;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Album;
use App\Models\Group;
use App\Models\Page;
use App\Models\Photo;
use App\Models\User;
use App\Support\Documentation;
use Filament\Facades\Filament;

beforeEach(function () {
    app()->instance(Documentation::class, new Documentation(base_path('tests/Fixtures/docs')));
});

it('uses the admin search provider', function () {
    expect(Filament::getGlobalSearchProvider())->toBeInstanceOf(AdminSearchProvider::class);
});

describe('for a super administrator', function () {
    beforeEach(fn () => actingAsRole(Role::SuperAdmin));

    it('links every result to a page that opens', function () {
        $album = Album::factory()->create(['title' => 'Zanzibar trip']);
        Photo::factory()->inAlbum($album)->create(['title' => 'Zanzibar beach']);
        Page::factory()->create(['title' => 'Zanzibar notes']);
        User::factory()->create(['name' => 'Zanzibar Fan']);

        $urls = searchUrls('zanzibar');

        expect($urls)->toHaveCount(4);

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        // Keeps the search field focused while a result is pressed, so Safari's click reaches the link.
        $this->get(Filament::getUrl())->assertSee('.fi-global-search-results-ctn a[href]', false);
    });

    it('searches more than the title', function () {
        $album = Album::factory()->create(['title' => 'Holidays', 'description' => 'Pictures from the lighthouse']);
        $photo = Photo::factory()->inAlbum($album)->create(['title' => null, 'description' => 'Lighthouse at dusk']);
        $user = User::factory()->create(['email' => 'keeper@lighthouse.test']);

        expect(searchUrls('lighthouse'))->toContain(
            AlbumResource::getUrl('edit', ['record' => $album]),
            PhotoResource::detailUrl($photo),
            UserResource::getUrl('edit', ['record' => $user]),
        );
    });

    it('finds admin pages, "new" shortcuts and documentation', function () {
        expect(collect(adminSearch('settings')[AdminSearchProvider::PAGES_CATEGORY])->pluck('url'))->toContain(GeneralSettings::getUrl())
            ->and(collect(adminSearch('favicon')[AdminSearchProvider::PAGES_CATEGORY])->pluck('url'))->toContain(GeneralSettings::getUrl())
            ->and(collect(adminSearch('new album')[AdminSearchProvider::PAGES_CATEGORY])->pluck('url'))->toContain(AlbumResource::getUrl('create'))
            ->and(collect(adminSearch('zebras')[AdminSearchProvider::DOCS_CATEGORY])->pluck('url'))->toContain(DocumentationPage::getUrl(['doc' => 'user/zebra']));
    });

    it('labels results in the reader\'s language', function () {
        app()->setLocale('de');
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $group = Group::factory()->create(['description' => 'Familie und Freunde']);

        expect(UserResource::getGlobalSearchResultDetails($user))->toBe(['E-Mail-Adresse' => 'ada@example.com'])
            ->and(GroupResource::getGlobalSearchResultDetails($group))->toBe(['Beschreibung' => 'Familie und Freunde']);
    });
});

describe('for people with fewer rights', function () {
    it('only shows what the user may open', function () {
        $owner = tap(User::factory()->create())->givePermissionTo(Permission::AccessAdmin->value, Permission::CreateAlbums->value);
        $this->actingAs($owner);

        $own = Album::factory()->create(['title' => 'Quokka own', 'owner_id' => $owner->id]);
        Album::factory()->create(['title' => 'Quokka other']);
        Page::factory()->create(['title' => 'Quokka page']);
        User::factory()->create(['name' => 'Quokka person']);

        expect(searchUrls('quokka'))->toBe([AlbumResource::getUrl('edit', ['record' => $own])])
            ->and(searchUrls('settings'))->not->toContain(GeneralSettings::getUrl())
            ->and(searchUrls('favicon'))->not->toContain(GeneralSettings::getUrl())
            ->and(searchUrls('new page'))->not->toContain(PageResource::getUrl('create'));
    });

    it('finds nothing for a member with a role on an album', function () {
        $this->actingAs(addCollaborator(Album::factory()->create(['title' => 'Camp']), CollaboratorRole::Editor));

        expect((new AdminSearchProvider)->getResults('Camp')->getCategories())->toBeEmpty();
    });
});
