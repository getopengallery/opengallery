{{--
    List / Grid switch at the end of the media table's toolbar
    (App\Filament\Tables\MediaLayoutSwitch). Phones show only the icons.
--}}
<div class="og-segmented og-layout-switch" role="group" aria-label="{{ __('Layout') }}">
    @foreach ($layouts as $layout => [$label, $icon])
        <button
            type="button"
            class="og-segmented-btn"
            aria-pressed="{{ $current === $layout ? 'true' : 'false' }}"
            title="{{ $label }}"
            wire:click="setLayout(@js($layout))"
            wire:loading.attr="disabled"
            wire:target="setLayout"
        >
            <x-filament::icon :icon="$icon" class="og-segmented-icon" />
            <span class="og-segmented-text">{{ $label }}</span>
        </button>
    @endforeach
</div>
