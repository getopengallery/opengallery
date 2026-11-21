@php($max = max(1, (int) $rows->max('views')))

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Most viewed albums')" :description="__('Last 30 days')">
        @if ($rows->isEmpty())
            <p class="og-muted">{{ __('No album has been opened in the last 30 days.') }}</p>
        @else
            <ol class="og-list">
                @foreach ($rows as $row)
                    <li class="og-bar-row">
                        <span class="og-bar-label">
                            <a href="{{ $url($row->album) }}" class="og-link">{{ $row->album->title }}</a>
                            <span class="og-bar" style="--share: {{ round($row->views / $max * 100) }}%" aria-hidden="true"></span>
                        </span>
                        <span class="og-num">{{ trans_choice(':count view|:count views', $row->views) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
