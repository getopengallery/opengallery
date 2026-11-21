{{-- Block: album-search. Search form of the album list. Props: query (the current search, may be empty). --}}
<form method="GET" action="{{ route('home') }}" role="search" class="flex w-full gap-2 sm:w-auto">
    <label for="album-search" class="sr-only">{{ __('Search albums') }}</label>
    <input id="album-search" type="search" name="q" value="{{ $query ?? '' }}" placeholder="{{ __('Search albums') }}" class="og-input min-w-0 flex-1 sm:w-64">
    <button type="submit" class="og-btn-secondary">{{ __('Search') }}</button>
</form>
