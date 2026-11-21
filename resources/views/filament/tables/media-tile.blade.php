{{--
    Media grid tile. Clicking the photo selects it (Filament's table selection,
    so bulk actions work as usual); "Open" leads to the photo's details.
    The checkboxes Filament renders are hidden for these tiles (theme-styles).
    Tiles keep the photo's shape (MediaGrid::ratioClass on the record) and,
    like the site's grids, load the first photos straight away and offer
    the small conversion through srcset.
--}}
@php
    $record = $getRecord();
    $key = (string) $record->getKey();
    $thumb = $record->mediaUrl('thumb');
    $srcset = $thumb ? \App\Support\Srcset::for($record, ['small' => $record->mediaUrl('small'), 'thumb' => $thumb]) : null;
    $ratio = $record->aspectRatio();
    $image = $thumb ? \App\Support\ImageLoading::next() : null;
    $title = $record->title ?: __('Untitled');
    $openable ??= true;                 // false in the photo picker: stay in the modal
    $single ??= false;                  // true when only one photo can be chosen (cover)
    $compact ??= false;                 // picker: title on the photo, no card text below
    $albums = $compact ? $record->albums->pluck('title')->implode(', ') : null;
    $isCover = isset($coverId) && (int) $coverId === (int) $record->getKey();
    // Not in the trash itself, but every album it is in is: hidden on the site until one comes back.
    $offSite = ! $record->trashed() && $record->albums->isNotEmpty() && $record->albums->every(fn ($album) => $album->trashed());
@endphp

<div @class(['og-pick', 'is-compact' => $compact])
     x-bind:class="{ 'is-selected': isRecordSelected(@js($key)), 'is-selecting': getSelectedRecordsCount() > 0 }">
    <button type="button"
            class="og-pick-photo"
            @if ($single)
                x-on:click.prevent.stop="if (isRecordSelected(@js($key))) { deselectAllRecords() } else { deselectAllRecords(); $nextTick(() => toggleSelectedRecord(@js($key))) }"
            @else
                x-on:click.prevent.stop="toggleSelectedRecord(@js($key))"
            @endif
            x-bind:aria-pressed="isRecordSelected(@js($key)) ? 'true' : 'false'"
            aria-label="{{ __('Select :title', ['title' => $title]) }}">
        @if ($thumb)
            {{-- Rows aim for at most 180px (theme-styles) and stretch to stay complete (media-grid-script). --}}
            <img src="{{ $thumb }}" @if ($srcset) srcset="{{ $srcset }}" sizes="(min-width: 1024px) {{ (int) ceil($ratio * 320) }}px, {{ (int) ceil($ratio * 240) }}px" @endif alt="" loading="{{ $image['loading'] }}" fetchpriority="{{ $image['fetchpriority'] }}" decoding="{{ $image['decoding'] }}">
        @else
            <span class="og-pick-empty">{{ __('No preview') }}</span>
        @endif

        @if ($compact)
            <span class="og-pick-caption" title="{{ $offSite ? __('Not on the site: all its albums are in the trash') : ($albums ? __('In :albums', ['albums' => $albums]) : '') }}">@if ($offSite)<span class="og-pick-cover">{{ __('Not on the site') }}</span>@endif @if ($isCover)<span class="og-pick-cover">{{ __('Cover') }}</span>@endif{{ $title }}</span>
        @elseif ($isCover)
            <span class="og-pick-cover">{{ __('Cover') }}</span>
        @endif

        <span class="og-pick-mark" aria-hidden="true">
            <x-filament::icon icon="heroicon-m-check" />
        </span>
    </button>

    @if ($openable && auth()->user()?->can('view', $record))
        <a href="{{ \App\Filament\Resources\Photos\PhotoResource::getUrl('view', ['record' => $record]) }}"
           class="og-pick-open"
           x-on:click.stop
           title="{{ __('Open details') }}"
           aria-label="{{ __('Open details of :title', ['title' => $title]) }}">
            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" />
        </a>
    @endif
</div>
