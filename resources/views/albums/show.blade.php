<x-app-layout :meta="$meta ?? null">
    <x-slot:title>{{ $album->title }}</x-slot:title>

    @php
        // Shortcuts into the admin for people who manage this album (the admin is where albums are edited).
        $user = auth()->user();
        $managesAlbum = $user?->can(\App\Enums\Permission::AccessAdmin->value) && $user->can('update', $album);
        // Collaborators work on the album on the same admin page, with what their role allows.
        $worksHere = ! $managesAlbum && $album->collaboratorRole($user) !== null;
        $uploadsHere = ($managesAlbum || $worksHere) && $user->can('upload', $album);
        $shareUrl = $managesAlbum && $album->visibility === \App\Enums\Visibility::Unlisted && $album->share_token
            ? route('albums.shared', $album->share_token)
            : null;
        // While downloads are on (Settings → Photos): visitors of albums that allow them, and people who may edit the album.
        $downloadsAll = $photos->total() > 0 && \App\Support\SiteSettings::downloadsEnabled() && ($album->allow_downloads || $user?->can('update', $album));
    @endphp

    <x-slot name="header">
        @if ($album->parent)
            <a href="{{ route('albums.show', $album->parent) }}" class="mb-3 inline-flex items-center gap-1 rounded-og text-sm text-muted hover:text-ink">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M12 5l-5 5 5 5"/></svg>
                {{ $album->parent->title }}
            </a>
        @endif

        <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-3">
            <div class="max-w-2xl">
                <h1 class="text-heading font-semibold">{{ $album->title }}</h1>
                @if ($album->description)
                    <p class="mt-3 text-muted">{{ $album->description }}</p>
                @endif
            </div>

            <div class="flex flex-col items-start gap-3 sm:items-end">
                <p class="text-sm text-muted">
                    {{ trans_choice('{0} No photos|{1} :count photo|[2,*] :count photos', $photos->total()) }}
                    @if ($album->visibility !== \App\Enums\Visibility::Public)
                        <span class="ms-3 rounded-full bg-surface px-2.5 py-1 text-caption text-ink">{{ $album->visibility->getLabel() }}</span>
                    @endif
                </p>

                @if ($managesAlbum || $worksHere || $downloadsAll)
                    <div class="flex flex-wrap gap-2">
                        @if ($uploadsHere)
                            <a href="{{ \App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager::addPhotosUrl($album) }}" class="og-btn-primary">{{ __('Add photos') }}</a>
                        @endif
                        @if ($managesAlbum)
                            <a href="{{ \App\Filament\Resources\Albums\AlbumResource::getUrl('edit', ['record' => $album]) }}" class="og-btn-secondary">{{ __('Edit album') }}</a>
                        @endif
                        @if ($worksHere)
                            <a href="{{ \App\Filament\Resources\Albums\AlbumResource::getUrl('edit', ['record' => $album]) }}" class="og-btn-secondary">{{ __('Manage album') }}</a>
                        @endif
                        @if ($downloadsAll)
                            <a href="{{ route('albums.download', $album) }}" class="og-btn-secondary" download>{{ __('Download all') }}</a>
                        @endif
                        @if ($shareUrl)
                            <button type="button" class="og-btn-secondary"
                                    x-data="{
                                        copied: false,
                                        copy() {
                                            const url = @js($shareUrl);
                                            if (! navigator.clipboard) { window.prompt(@js(__('Share link')), url); return; }
                                            navigator.clipboard.writeText(url).then(
                                                () => { this.copied = true; setTimeout(() => this.copied = false, 2000); },
                                                () => window.prompt(@js(__('Share link')), url),
                                            );
                                        },
                                    }"
                                    x-on:click="copy()">
                                <span x-show="! copied">{{ __('Copy link') }}</span>
                                <span x-show="copied" x-cloak role="status">{{ __('Link copied') }}</span>
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="og-container space-y-14">
        @if ($children->isNotEmpty())
            <section aria-labelledby="sub-albums">
                <h2 id="sub-albums" class="mb-5 text-title font-semibold">{{ __('Albums in :album', ['album' => $album->title]) }}</h2>
                <x-block name="album-grid" :albums="$children" />
                <div class="mt-14">{{ $children->links() }}</div>
            </section>
        @endif

        @if ($photos->isEmpty())
            @if ($children->isEmpty())
                <x-block name="empty-state" :message="__('This album has no photos yet.')" />
            @endif
        @else
            <section aria-label="{{ __('Photos') }}">
                <x-block name="photo-grid" :photos="$photos" />
                <div class="mt-14">{{ $photos->links() }}</div>
            </section>
        @endif
    </div>
</x-app-layout>
