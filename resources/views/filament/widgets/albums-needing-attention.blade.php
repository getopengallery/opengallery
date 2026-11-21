<x-filament-widgets::widget>
    <x-filament::section :heading="__('Albums needing attention')" :description="$total ? trans_choice(':count album|:count albums', $total) : null">
        @if ($albums->isEmpty())
            <p class="og-muted">{{ __('Every album has photos, a cover and a valid link.') }}</p>
        @else
            <ul class="og-list">
                @foreach ($albums as $album)
                    <li>
                        <span class="og-todo-text">
                            <a href="{{ \App\Filament\Resources\Albums\AlbumResource::getUrl('edit', ['record' => $album]) }}" class="og-link">{{ $album->title }}</a>
                            @if ($album->owner)
                                <span class="og-muted">{{ $album->owner->name }}</span>
                            @endif
                        </span>
                        <span class="og-badges">
                            @foreach (\App\Support\Dashboard\AlbumStats::issues($album) as $issue)
                                <x-filament::badge :color="$issue === 'Empty' || $issue === 'No cover' ? 'gray' : 'warning'" size="sm">{{ __($issue) }}</x-filament::badge>
                            @endforeach
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
