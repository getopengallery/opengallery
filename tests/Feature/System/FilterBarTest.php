<?php

use App\Enums\Role;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Tables\FilterBar;
use App\Models\Album;
use App\Models\Photo;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('gallery');
    actingAsRole(Role::Admin);
});

it('puts the primary filters first and keeps the others for "More filters"', function () {
    $table = Livewire::test(ListPhotos::class)->instance()->getTable();
    $chips = collect(FilterBar::chips($table));

    expect($chips->take(5)->pluck('name')->all())->toBe(['albums', 'taken', 'tags', 'uploader', 'orientation'])
        ->and($chips->take(5)->every(fn (array $chip): bool => $chip['primary']))->toBeTrue()
        ->and($chips->skip(5)->contains(fn (array $chip): bool => $chip['primary']))->toBeFalse()
        ->and($chips->pluck('name'))->toContain('resolution', 'file_name', 'favorites');
});

it('makes relationship filters and long option lists searchable, but not short static ones', function () {
    $table = Livewire::test(ListPhotos::class)->instance()->getTable();
    $chips = collect(FilterBar::chips($table))->keyBy('name');

    // Albums and uploaders grow over time, so they are searchable even with one or two options.
    expect($chips['albums']['searchable'])->toBeTrue()
        ->and($chips['uploader']['searchable'])->toBeTrue()
        ->and($chips['orientation']['searchable'])->toBeFalse()
        ->and($chips['favorites']['searchable'])->toBeFalse();

    $table->filters([SelectFilter::make('size')->options(array_map(fn (int $n): string => "Option $n", range(1, FilterBar::SEARCH_THRESHOLD)))]);
    expect(FilterBar::chips($table)[0]['searchable'])->toBeFalse();

    $table->filters([SelectFilter::make('size')->options(array_map(fn (int $n): string => "Option $n", range(1, FilterBar::SEARCH_THRESHOLD + 1)))]);
    expect(FilterBar::chips($table)[0]['searchable'])->toBeTrue();
});

it('names a date range after a preset when it matches one, and writes out any other range', function (array $state, string $summary) {
    $page = Livewire::test(ListPhotos::class)->filterTable('taken', $state);
    $chip = collect(FilterBar::chips($page->instance()->getTable()))->firstWhere('name', 'taken');

    expect($chip['active'])->toBeTrue()
        ->and($chip['summary'])->toBe($summary);
})->with([
    'the last 7 days' => [fn () => ['from' => today()->subDays(6)->toDateString(), 'until' => today()->toDateString()], 'Last 7 days'],
    'this year' => [fn () => ['from' => today()->startOfYear()->toDateString(), 'until' => today()->endOfYear()->toDateString()], (string) today()->year],
    'a custom range' => [['from' => '2024-01-01', 'until' => '2024-03-15'], '01.01.2024 – 15.03.2024'],
    'only a start' => [['from' => '2024-01-01'], 'since 01.01.2024'],
    'only an end' => [['until' => '2024-03-15'], 'until 15.03.2024'],
]);

it('lists at most the most used related records, and always the selected one', function () {
    $busy = Album::factory()->create(['title' => 'Busy']);
    Photo::factory()->count(3)->inAlbum($busy)->create();
    $quiet = Album::factory()->count(FilterBar::OPTION_LIMIT)->create();
    $quiet->each(fn (Album $album) => Photo::factory()->inAlbum($album)->create());

    $chip = fn (array $selected = []) => collect(FilterBar::chips(
        Livewire::test(ListPhotos::class)->filterTable('albums', $selected)->instance()->getTable()
    ))->firstWhere('name', 'albums');

    $limited = $chip();
    $left = $quiet->first(fn (Album $album): bool => ! isset($limited['options'][(string) $album->id]));

    expect($limited['limited'])->toBeTrue()
        ->and($limited['options'])->toHaveCount(FilterBar::OPTION_LIMIT)
        ->and($limited['options'][(string) $busy->id])->toBe(['label' => 'Busy', 'count' => 3])
        ->and($left)->not->toBeNull();

    $selected = $chip([$left->id]);

    expect($selected['options'])->toHaveCount(FilterBar::OPTION_LIMIT + 1)
        ->and($selected['options'][(string) $left->id])->toBe(['label' => $left->title, 'count' => 1])
        ->and($selected['summary'])->toBe($left->title);
});

it('counts only the rows of the table, so photos in the trash do not count', function () {
    $album = Album::factory()->create();
    Photo::factory()->count(2)->inAlbum($album)->create();
    Photo::factory()->inAlbum($album)->create()->delete();

    $table = Livewire::test(ListPhotos::class)->instance()->getTable();

    expect(collect(FilterBar::chips($table))->firstWhere('name', 'albums')['options'][(string) $album->id]['count'])->toBe(2);
});

it('refuses a filter it cannot show instead of silently hiding it', function () {
    $table = Livewire::test(ListPhotos::class)->instance()->getTable();
    $table->filters([Filter::make('odd')->schema([TextInput::make('a'), TextInput::make('b')])]);

    FilterBar::chips($table);
})->throws(LogicException::class, 'cannot show the filter [odd]');
