@php($issueColor = fn (string $issue): string => $issue === 'Empty' ? 'gray' : 'warning')

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Your albums')">
        @unless ($albums->isEmpty())
            <x-slot name="afterHeader">
                <x-filament::link :href="$allAlbumsUrl">{{ __('All albums') }}</x-filament::link>
            </x-slot>
        @endunless

        @if ($uploadUrl || $newAlbumUrl)
            <x-slot name="footer">
                <div class="og-actions">
                    @if ($uploadUrl)
                        <x-filament::button tag="a" :href="$uploadUrl" icon="heroicon-o-arrow-up-tray" size="sm" color="gray">{{ __('Upload photos') }}</x-filament::button>
                    @endif
                    @if ($newAlbumUrl)
                        <x-filament::button tag="a" :href="$newAlbumUrl" icon="heroicon-o-folder-plus" size="sm">{{ __('New album') }}</x-filament::button>
                    @endif
                </div>
            </x-slot>
        @endif

        @if ($inbox)
            <p class="og-callout">
                {{ trans_choice(':count photo waits in “Unsorted uploads”.|:count photos wait in “Unsorted uploads”.', $inbox['photos']) }}
                <x-filament::link :href="$inboxUrl">{{ __('Sort them into albums') }}</x-filament::link>
            </p>
        @endif

        @if ($albums->isEmpty())
            <p class="og-muted">{{ $canCreate
                ? __('You have no albums yet. Create one, or upload photos and sort them into albums later.')
                : __('You have no albums yet. Photos you upload wait in “Unsorted uploads” until they are added to an album.') }}</p>
        @else
            <ul class="og-list">
                @foreach ($albums as $album)
                    <li>
                        <span class="og-row-main">
                            <a href="{{ \App\Filament\Resources\Albums\AlbumResource::getUrl('edit', ['record' => $album]) }}" class="og-link">{{ $album->title }}</a>
                            @foreach (\App\Support\Dashboard\AlbumStats::issues($album) as $issue)
                                @unless ($issue === 'Empty')
                                    <x-filament::badge :color="$issueColor($issue)" size="sm">{{ __($issue) }}</x-filament::badge>
                                @endunless
                            @endforeach
                        </span>
                        <span class="og-muted og-num">{{ trans_choice('{0} Empty|{1} :count photo|[2,*] :count photos', $album->photos_count) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
