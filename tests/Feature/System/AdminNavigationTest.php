<?php

use App\Support\AdminNavigation;
use Illuminate\Support\Str;

it('treats every URL of the site as a full page load and every admin URL as an SPA page', function (string $path, bool $fullPage) {
    expect(Str::is(AdminNavigation::fullPageUrls('admin'), url($path)))->toBe($fullPage);
})->with([
    'home' => ['/', true],
    'home with query' => ['/?page=2', true],
    'album' => ['albums/summer', true],
    'media file' => ['media/1/thumb', true],
    'login' => ['login', true],
    'admin dashboard' => ['admin', false],
    'admin media library' => ['admin/media', false],
    'admin settings' => ['admin/settings/general', false],
    'admin with query' => ['admin?tab=1', false],
]);

it('follows the panel path it is given', function () {
    $patterns = AdminNavigation::fullPageUrls('/backoffice/');

    expect(Str::is($patterns, url('admin/media')))->toBeTrue()
        ->and(Str::is($patterns, url('backoffice/media')))->toBeFalse();
});
