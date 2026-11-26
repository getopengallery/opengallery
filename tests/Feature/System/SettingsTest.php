<?php

use App\Enums\PhotoOrder;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;

it('reads the settings from the cache store once per page view, not for every setting', function () {
    config(['cache.default' => 'database']);
    Album::factory()->visibility(Visibility::Public)->create();
    DB::enableQueryLog();

    $this->get(route('home'))->assertOk();

    // The cache starts empty: one read finds nothing, the settings are stored, one read gets them back.
    // Every other setting the page needs comes from memory (before: one read per setting, ~90).
    $cacheReads = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "cache"'));
    expect($cacheReads)->toHaveCount(2);
});

it('returns a changed setting at once, in the same request', function () {
    Setting::put('site.name', 'Before');
    Setting::get('site.name');

    Setting::put('site.name', 'After');

    expect(Setting::get('site.name'))->toBe('After');
});

it('keeps an order chosen explicitly for an album', function () {
    config(['gallery.default_photo_order' => PhotoOrder::TitleAsc->value]);

    expect(Album::factory()->create(['photo_order' => PhotoOrder::UploadedDesc])->photo_order)->toBe(PhotoOrder::UploadedDesc);
});

it('starts with downloads off and favorites, information and the slideshow on', function () {
    expect(SiteSettings::downloadsEnabled())->toBeFalse()
        ->and(SiteSettings::favoritesEnabled())->toBeTrue()
        ->and(SiteSettings::photoInfoEnabled())->toBeTrue()
        ->and(SiteSettings::slideshowEnabled())->toBeTrue()
        ->and(SiteSettings::slideshowSeconds())->toBe(5);
});

it('keeps a stored value inside the range', function (mixed $stored, int $expected) {
    Setting::put(SiteSettings::SLIDESHOW_SECONDS, $stored);

    expect(SiteSettings::slideshowSeconds())->toBe($expected);
})->with([[1, 2], [500, 60], [0, 5]]);
