<x-filament-widgets::widget>
    <x-filament::section :heading="__('Trash')" :description="__('Restore what you need, or delete it permanently.')">
        <ul class="og-list">
            @if ($counts['albums'] > 0)
                <li>
                    <x-filament::link :href="$albumsUrl">{{ __('Albums') }}</x-filament::link>
                    <span class="og-muted og-num">{{ trans_choice(':count album|:count albums', $counts['albums']) }}</span>
                </li>
            @endif
            @if ($counts['photos'] > 0)
                <li>
                    <x-filament::link :href="$photosUrl">{{ __('Media') }}</x-filament::link>
                    <span class="og-muted og-num">{{ trans_choice(':count photo|:count photos', $counts['photos']) }}</span>
                </li>
            @endif
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
