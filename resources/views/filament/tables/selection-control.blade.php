{{--
    The photo grid's one selection control (MediaGrid::selectionActions):
    "Select all" while nothing is selected, then "3 selected · Select all · ×"
    in one block. Replaces Filament's selection row under the toolbar
    (hidden in theme-styles), so the count is said once. Runs inside the
    table's Alpine component.
--}}
@php
    $selectAll = $acrossPages ? 'selectAllRecords()' : 'selectRecords(getRecordsOnPage())';
    $canSelectMore = $acrossPages ? 'canSelectAllRecords()' : '! areRecordsSelected(getRecordsOnPage())';
@endphp

<div class="og-selection">
    <x-filament::button
        color="gray"
        icon="heroicon-o-check-circle"
        x-show="! getSelectedRecordsCount()"
        x-on:click="{{ $selectAll }}"
    >
        {{ __('Select all') }}
    </x-filament::button>

    <div class="og-segmented" role="group" aria-label="{{ __('Selection') }}" x-show="getSelectedRecordsCount()" x-cloak>
        <span
            class="og-segmented-label"
            role="status"
            aria-live="polite"
            x-text="@js(__(':count selected')).replace(':count', new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale()))).format(getSelectedRecordsCount()))"
        ></span>
        <button type="button" class="og-segmented-btn" x-show="{{ $canSelectMore }}" x-on:click="{{ $selectAll }}">
            {{ __('Select all') }}
        </button>
        <button
            type="button"
            class="og-segmented-btn"
            aria-label="{{ __('Clear selection') }}"
            title="{{ __('Clear selection') }}"
            x-on:click="deselectAllRecords()"
        >
            <x-filament::icon icon="heroicon-m-x-mark" class="og-segmented-icon" />
        </button>
    </div>
</div>
