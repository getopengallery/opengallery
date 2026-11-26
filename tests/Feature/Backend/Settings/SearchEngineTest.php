<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\SearchEngineSettings;
use App\Models\Setting;
use App\Support\SiteSettings;
use Livewire\Livewire;

beforeEach(fn () => actingAsRole(Role::Admin));

it('saves the search engine settings and adds the verification tags', function () {
    Livewire::test(SearchEngineSettings::class)
        ->assertSee('User-agent: GPTBot')
        ->set('data.block_ai_crawlers', false)
        ->set('data.robots_extra', "User-agent: Example-Bot\nDisallow: /")
        ->assertDontSee('User-agent: GPTBot')
        ->assertSee('User-agent: Example-Bot')
        ->set('data.description', 'Our family photos.')
        ->set('data.google_verification', '<meta name="google-site-verification" content="abc123_XYZ" />')
        ->set('data.bing_verification', 'BING42')
        ->call('save')
        ->assertHasNoErrors();

    expect(SiteSettings::description())->toBe('Our family photos.')
        ->and(SiteSettings::blocksAiCrawlers())->toBeFalse()
        ->and(SiteSettings::robotsExtra())->toBe("User-agent: Example-Bot\nDisallow: /")
        ->and(SiteSettings::googleVerification())->toBe('abc123_XYZ');

    $this->get(route('home'))
        ->assertSee('<meta name="google-site-verification" content="abc123_XYZ">', false)
        ->assertSee('<meta name="msvalidate.01" content="BING42">', false)
        ->assertSee('<meta property="og:description" content="Our family photos.">', false);
});

it('keeps the verification codes while search engines are turned off', function () {
    Setting::put(SiteSettings::GOOGLE_VERIFICATION, 'abc123');
    Setting::put(SiteSettings::BING_VERIFICATION, 'BING42');

    Livewire::test(SearchEngineSettings::class)
        ->set('data.search_indexing', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(SiteSettings::searchIndexing())->toBeFalse()
        ->and(SiteSettings::googleVerification())->toBe('abc123')
        ->and(SiteSettings::bingVerification())->toBe('BING42');
});
