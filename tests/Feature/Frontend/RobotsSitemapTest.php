<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Page;
use App\Models\Setting;
use App\Support\SiteSettings;

describe('robots.txt', function () {
    it('tells crawlers what to leave out, depending on the search engine settings', function (array $settings, array $included, array $excluded, string $meta, int $sitemapStatus) {
        foreach ($settings as $key => $value) {
            Setting::put($key, $value);
        }
        $album = Album::factory()->visibility(Visibility::Public)->create();

        $robots = $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        expect($robots)->toContain(...array_map(fn (string $text) => str_replace('{sitemap}', route('search.sitemap'), $text), $included))
            ->not->toContain(...$excluded);
        $this->get(route('search.sitemap'))->assertStatus($sitemapStatus);
        $this->get(route('albums.show', $album))->assertSee($meta, false);
    })->with([
        'by default: search engines in, AI crawlers and private areas out' => [
            [],
            ["User-agent: GPTBot\n", "User-agent: YouBot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\n", 'Sitemap: {sitemap}'],
            ['Googlebot'],
            '<meta name="robots" content="max-image-preview:large, noai, noimageai">',
            200,
        ],
        'with AI crawlers allowed and the admin\'s own rules' => [
            [SiteSettings::BLOCK_AI_CRAWLERS => false, SiteSettings::ROBOTS_EXTRA => "User-agent: Example-Bot\r\nDisallow: /pages/"],
            ["User-agent: Example-Bot\nDisallow: /pages/\n\nSitemap:"],
            ['GPTBot'],
            '<meta name="robots" content="max-image-preview:large">',
            200,
        ],
        'with indexing off: search engines out, but not link preview apps' => [
            [SiteSettings::SEARCH_INDEXING => false],
            ["User-agent: *\nDisallow: /", "User-agent: Twitterbot\n"],
            ['Sitemap:'],
            '<meta name="robots" content="noindex, nofollow">',
            404,
        ],
    ]);
});

describe('the sitemap', function () {
    it('lists only what visitors without an account may open', function () {
        $public = Album::factory()->visibility(Visibility::Public)->create();
        $hidden = [
            Album::factory()->visibility(Visibility::Unlisted)->create(),
            Album::factory()->visibility(Visibility::Registered)->create(),
            Album::factory()->visibility(Visibility::Private)->create(),
            Album::factory()->visibility(Visibility::Public)->create(['password' => 'secret']),
            Album::factory()->visibility(Visibility::Public)->create(['access_expires_at' => now()->subDay()]),
        ];
        $page = Page::factory()->create();
        $hiddenPages = [Page::factory()->membersOnly()->create(), Page::factory()->draft()->create()];

        $response = $this->get(route('search.sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.route('home').'</loc>', false)
            ->assertSee('<loc>'.route('albums.show', $public).'</loc>', false)
            ->assertSee('<loc>'.route('pages.show', $page).'</loc>', false);

        foreach ($hidden as $album) {
            $response->assertDontSee(route('albums.show', $album));
        }
        foreach ($hiddenPages as $hiddenPage) {
            $response->assertDontSee(route('pages.show', $hiddenPage));
        }
        expect(simplexml_load_string($response->getContent()))->not->toBeFalse();
    });

    it('lists no albums while public albums are off', function () {
        Setting::put(SiteSettings::PUBLIC_ALBUMS, false);
        Setting::put(SiteSettings::GUEST_HOME, 'login');
        SiteSettings::apply();
        $album = Album::factory()->visibility(Visibility::Public)->create();

        $this->get(route('search.sitemap'))
            ->assertOk()
            ->assertDontSee(route('albums.show', $album))
            ->assertDontSee('<loc>'.route('home').'</loc>', false);
    });
});
