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
            @php
                $cover = $favorites->first()?->getFirstMedia(\App\Models\Photo::COLLECTION);
            @endphp
            <section aria-labelledby="favorites">
                <h2 id="favorites" class="sr-only">{{ __('Favorites') }}</h2>
                <ul role="list" class="grid grid-cols-2 gap-x-5 gap-y-9 sm:grid-cols-3 lg:grid-cols-4">
                    <li>
                        <a href="{{ route('favorites.index') }}" class="group block rounded-og">
                            <div class="aspect-[3/2] overflow-hidden rounded-og bg-surface">
                                @if ($cover)
                                    <img src="{{ route('media.show', [$cover, 'thumb']) }}" alt="" class="h-full w-full object-cover">
                                @endif
                            </div>
                            <h3 class="mt-3 flex items-center gap-1.5 font-semibold leading-snug text-ink">
                                <svg viewBox="0 0 20 20" class="h-4 w-4 shrink-0 text-primary" fill="currentColor" aria-hidden="true"><path d="M10 17.5s-6.5-3.9-6.5-8.6A3.6 3.6 0 0 1 10 6.6a3.6 3.6 0 0 1 6.5 2.3c0 4.7-6.5 8.6-6.5 8.6Z"/></svg>
                                <span class="decoration-1 underline-offset-4 group-hover:underline">{{ __('Favorites') }}</span>
                            </h3>
                            <p class="mt-0.5 text-caption text-muted">{{ trans_choice('{0} Empty|{1} :count photo|[2,*] :count photos', $favorites->total()) }}</p>
                        </a>
                    </li>
                </ul>
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
