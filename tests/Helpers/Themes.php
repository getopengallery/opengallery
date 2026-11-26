<?php

use App\Enums\Visibility;
use App\Extensions\ThemeManager;
use App\Models\Album;
use Filament\Support\Colors\Color;
use Illuminate\Pagination\LengthAwarePaginator;

/** Uses the fixture themes in tests/Fixtures/themes ("plain" as default), optionally activating and booting $activate. */
function useFixtureThemes(?string $activate = null): ThemeManager
{
    $themes = new ThemeManager(base_path('tests/Fixtures/themes'), 'plain');
    app()->instance(ThemeManager::class, $themes);

    if ($activate !== null) {
        $themes->activate($activate);
        $themes->boot();
    }

    return $themes;
}

/**
 * Activates the standalone Scrapbook theme with $options and boots it.
 *
 * @param  array<string, mixed>  $options
 */
function useScrapbookTheme(array $options = []): ThemeManager
{
    $themes = app(ThemeManager::class);
    $themes->activate('scrapbook');
    $themes->saveOptions('scrapbook', $options);
    $themes->boot();

    return $themes;
}

/** A new public album "Summer fair" with one photo. */
function scrapbookAlbumWithPhoto(): Album
{
    $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer fair']);
    galleryPhoto($album, null, 'Candy floss.jpg', 800, 600);

    return $album;
}

/**
 * Saves $options for the Default theme.
 *
 * @param  array<string, mixed>  $options
 */
function themeOptions(array $options): void
{
    app(ThemeManager::class)->saveOptions('default', $options);
}

/** Page 2 of 5 of ten numbers, as a paginator for the pagination views. */
function pagedItems(): LengthAwarePaginator
{
    return new LengthAwarePaginator(range(1, 10), 50, 10, 2, ['path' => '/albums']);
}

/** The oklch() string AdminTheme pins as shade 600 for $hex. */
function expectedShade(string $hex): string
{
    preg_match('/oklch\(([\d.]+)\s+(.+)\)/', Color::convertToOklch($hex), $m);

    return sprintf('oklch(%.3f %s)', $m[1], $m[2]);
}
