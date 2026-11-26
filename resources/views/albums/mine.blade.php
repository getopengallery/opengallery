<x-app-layout :meta="\App\Support\PageMeta::hidden()">
    <x-slot:title>{{ __('My albums') }}</x-slot:title>

    @php
        // Only people who may create albums (in the admin) get the "own albums" part.
        $createsAlbums = auth()->user()->can('create', \App\Models\Album::class)
            && auth()->user()->can(\App\Enums\Permission::AccessAdmin->value);
        // Albums worked on as a collaborator are managed in the admin's Albums section.
        $worksOnAlbums = \App\Support\AlbumCollaboration::albumIdsFor(auth()->user()) !== [];
    @endphp

    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <h1 class="text-heading font-semibold">{{ __('My albums') }}</h1>
            <div class="flex flex-wrap gap-2">
                @if ($worksOnAlbums)
                    <a href="{{ \App\Filament\Resources\Albums\AlbumResource::getUrl() }}" class="og-btn-secondary">{{ __('Manage albums') }}</a>
                @endif
                @if ($createsAlbums)
                    <a href="{{ \App\Filament\Resources\Albums\AlbumResource::getUrl('create') }}" class="og-btn-primary">{{ __('New album') }}</a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="og-container space-y-14">
        @if (session('collaboration_status'))
            <p role="status" class="rounded-og bg-surface px-4 py-3 text-sm">{{ session('collaboration_status') }}</p>
        @endif

        @if ($favorites)
            <section aria-labelledby="favorites">
                <h2 id="favorites" class="sr-only">{{ __('Favorites') }}</h2>
                <x-block name="album-grid" :albums="[]">
                    <li><x-block name="favorites-tile" :favorites="$favorites" /></li>
                </x-block>
            </section>
        @endif

        @if ($own->isNotEmpty())
            <section aria-labelledby="own">
                <h2 id="own" class="sr-only">{{ __('Albums you own') }}</h2>
                <x-block name="album-grid" :albums="$own" />
                <div class="mt-14">{{ $own->links() }}</div>
            </section>
        @elseif ($createsAlbums)
            <x-block name="empty-state" :message="__('You don\'t have any albums of your own yet.')">
                <a href="{{ \App\Filament\Resources\Albums\AlbumResource::getUrl('create') }}" class="og-btn-primary">{{ __('New album') }}</a>
            </x-block>
        @endif

        @if ($shared->isNotEmpty())
            <section aria-labelledby="shared">
                <h2 id="shared" class="mb-5 text-title font-semibold">{{ __('Shared with you') }}</h2>
                <x-block name="album-grid" :albums="$shared" />
                <div class="mt-14">{{ $shared->links() }}</div>
            </section>
        @elseif ($own->isEmpty() && ! $createsAlbums)
            <x-block name="empty-state" :message="__('Albums that are shared with you will appear here. Ask the people who run this gallery to share an album with you.')" />
        @endif
    </div>
</x-app-layout>
