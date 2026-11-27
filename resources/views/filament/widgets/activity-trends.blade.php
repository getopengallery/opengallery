@php
    $note = match (true) {
        ! $level->logsLogins() => __('The activity log does not record logins and visits (Settings), so new weeks stay empty.'),
        ! $level->logsVisits() => __('The activity log does not record album views or downloads (Settings), so only logins are counted.'),
        default => null,
    };
@endphp

{{-- Figures of the last full week in the library ledger style; the section's padding is removed (theme-styles). --}}
<x-filament-widgets::widget>
    <x-filament::section class="og-ledger-section" :heading="__('Last week')" :description="$note">
        @if ($more)
            <x-slot name="afterHeader">
                <a href="{{ $more }}" class="og-link">{{ __('All statistics') }}</a>
            </x-slot>
        @endif

        <div class="og-ledger">
            @foreach ($figures as $figure)
                <div class="og-ledger-item">
                    <span class="og-ledger-value">{{ \Illuminate\Support\Number::format($figure['value']) }}</span>
                    <span class="og-ledger-label">{{ $figure['label'] }}</span>
                    <span class="og-ledger-change">{{ $figure['change'] ?? __('No week to compare with') }}</span>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
