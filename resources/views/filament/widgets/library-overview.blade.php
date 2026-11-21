{{-- One line of figures separated by rules; the section's padding is removed so the rules run edge to edge (theme-styles). --}}
<x-filament-widgets::widget>
    <x-filament::section class="og-ledger-section">
        <div class="og-ledger">
            @foreach ($this->getFigures() as $figure)
                @if ($figure['url'])
                    <a href="{{ $figure['url'] }}" class="og-ledger-item">
                @else
                    <div class="og-ledger-item">
                @endif
                        <span class="og-ledger-value">{{ $figure['value'] }}</span>
                        <span class="og-ledger-label">{{ $figure['label'] }}</span>
                @if ($figure['url'])
                    </a>
                @else
                    </div>
                @endif
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
