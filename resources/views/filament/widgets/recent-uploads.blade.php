<x-filament-widgets::widget>
    <x-filament::section :heading="__('Your recent uploads')" :description="trans_choice('{0} None in the last 7 days|{1} :count in the last 7 days|[2,*] :count in the last 7 days', $thisWeek)">
        @if ($photos->isEmpty())
            <p class="og-muted">{{ __('Photos you upload appear here.') }}</p>
        @else
            <ul class="og-thumbs">
                @foreach ($photos as $photo)
                    @php($label = $photo->title ?: ($photo->original()?->file_name ?? __('Untitled photo')))
                    <li>
                        <a href="{{ \App\Filament\Resources\Photos\PhotoResource::getUrl('view', ['record' => $photo]) }}" title="{{ $label }}">
                            @if ($thumb = $photo->mediaUrl('thumb'))
                                <img src="{{ $thumb }}" alt="{{ $label }}" loading="lazy" class="og-thumb">
                            @else
                                <span class="og-thumb-missing">{{ $label }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="og-note"><x-filament::link :href="$allUrl">{{ __('All your uploads') }}</x-filament::link></p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
