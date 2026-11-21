<?php

use App\Enums\Role;
use App\Extensions\ThemeManager;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Database\Seeders\DemoPagesSeeder;
use Livewire\Livewire;

it('shows published pages to everyone, even with public albums turned off', function () {
    Setting::put(SiteSettings::PUBLIC_ALBUMS, false);
    SiteSettings::apply();
    $page = Page::factory()->create(['title' => 'Imprint', 'body' => '<p>Example family, Example Street 1</p>']);

    $this->get(route('pages.show', $page))
        ->assertOk()
        ->assertSee('Imprint')
        ->assertSee('Example Street 1');
});

it('hides drafts and keeps members-only pages from guests', function () {
    $draft = Page::factory()->draft()->create();
    $members = Page::factory()->membersOnly()->create();

    $this->get(route('pages.show', $draft))->assertNotFound();
    $this->get(route('pages.show', $members))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('pages.show', $members))->assertOk();
});

it('does not run scripts written into a page', function () {
    $page = Page::factory()->create(['body' => '<p>Hello</p><script>alert(1)</script><img src=x onerror="alert(2)">']);

    $this->get(route('pages.show', $page))
        ->assertSee('Hello')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('onerror', false);
});

it('lets admins create pages with an address taken from the title', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    Livewire::test(CreatePage::class)
        ->set('data.title', 'About us')
        ->set('data.body', '<p>We are a scout troop.</p>')
        ->set('data.is_published', true)
        ->call('create')
        ->assertHasNoErrors();

    expect(Page::sole()->slug)->toBe('about-us');
});

it('links the page edit page to the published page on the site, not to drafts', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
    $page = Page::factory()->create();
    $draft = Page::factory()->draft()->create();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertActionHasUrl('open', route('pages.show', $page));
    Livewire::test(EditPage::class, ['record' => $draft->getRouteKey()])
        ->assertActionHidden('open');
});

it('keeps page management to staff who may manage pages', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Moderator->value))
        ->get(PageResource::getUrl())
        ->assertForbidden();
});

it('seeds demo pages once and links them without overriding chosen links', function () {
    $this->seed(DemoPagesSeeder::class);
    $this->seed(DemoPagesSeeder::class);

    $options = app(ThemeManager::class)->options('default');
    expect(Page::count())->toBe(5)
        ->and(Page::whereKey($options['header_pages'])->pluck('slug')->all())->toBe(['about'])
        ->and(Page::whereKey($options['footer_pages'])->pluck('slug')->sort()->values()->all())->toBe(['imprint', 'privacy']);

    $this->get(route('home'))->assertSee('About')->assertSee('Privacy')->assertDontSee('Photo walk guide');

    app(ThemeManager::class)->saveOptions('default', [...$options, 'footer_pages' => []]);
    $this->seed(DemoPagesSeeder::class);

    expect(app(ThemeManager::class)->option('footer_pages'))->toBe([]);
});

it('points to the real theme settings menu in the page form', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    Livewire::test(CreatePage::class)->assertSee('Appearance &amp; extensions → Themes', false);
});
