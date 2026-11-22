<x-app-layout :meta="filled($search ?? null) ? \App\Support\PageMeta::hidden() : null">
    <x-slot:title>{{ __('Albums') }}</x-slot:title>

    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <h1 class="text-heading font-semibold">{{ __('Albums') }}</h1>
            @if ($search !== '' || $albums->isNotEmpty())
                <x-block name="album-search" :query="$search" />
            @endif
        </div>
    </x-slot>

    <div class="og-container">
        @if ($search !== '')
            <p class="mb-6 text-muted" role="status">
                {{ trans_choice('{0} No albums match ":query".|{1} :count album matches ":query".|[2,*] :count albums match ":query".', $albums->total(), ['query' => $search]) }}
                <a href="{{ route('home') }}" class="og-link ms-2 text-ink">{{ __('Show all albums') }}</a>
            </p>
        @endif

        @if ($albums->isEmpty())
            {{-- An empty search result is announced by the line above. --}}
            @if ($search === '')
                <x-block name="empty-state" :message="auth()->check()
                    ? __('No albums have been shared with you yet.')
                    : __('There are no public albums yet. Log in to see albums shared with you.')">
                    @guest
                        <a href="{{ route('login') }}" class="og-btn-primary">{{ __('Log in') }}</a>
                    @else
                        @if (auth()->user()->can('create', \App\Models\Album::class) && auth()->user()->can(\App\Enums\Permission::AccessAdmin->value))
                            <a href="{{ \App\Filament\Resources\Albums\AlbumResource::getUrl('create') }}" class="og-btn-primary">{{ __('New album') }}</a>
                        @endif
                    @endguest
                </x-block>
            @endif
        @else
            <x-block name="album-grid" :albums="$albums" />

            <div class="mt-14">{{ $albums->links() }}</div>
        @endif

        @if (($photos ?? null)?->isNotEmpty())
            <div @class(['mt-14' => $albums->isNotEmpty()])>
                <x-block name="search-photos" :photos="$photos" :query="$search" />
            </div>
        @endif
    </div>
</x-app-layout>
