@php
    $weeks = $calendar['weeks'];
    $guests = $mode === \App\Support\DashboardStats::GUESTS;
    $summary = $guests
        ? trans_choice('{0} No guest visits in the last year.|{1} :count guest visit on :days days in the last year.|[2,*] :count guest visits on :days days in the last year.', $calendar['people'], ['days' => $calendar['activeDays']])
        : trans_choice('{0} No members were active in the last year.|{1} :count member was active on :days days in the last year.|[2,*] :count members were active on :days days in the last year.', $calendar['people'], ['days' => $calendar['activeDays']]);
    $unit = fn (int $n) => $guests ? trans_choice(':count guest|:count guests', $n) : trans_choice(':count member|:count members', $n);
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Activity')" :description="$summary">
        <x-slot name="afterHeader">
            <x-filament::tabs :label="__('Show activity of')">
                <x-filament::tabs.item :active="! $guests" wire:click="setMode('members')">{{ __('Members') }}</x-filament::tabs.item>
                <x-filament::tabs.item :active="$guests" wire:click="setMode('guests')">{{ __('Guests') }}</x-filament::tabs.item>
            </x-filament::tabs>
        </x-slot>

        @php
            $note = match (true) {
                ! $level->logsChanges() => __('The activity log is turned off in Settings, so nothing new is recorded.'),
                ! $level->logsLogins() => __('The activity log records only changes (Settings), so new logins and visits are not counted.'),
                $guests && ! $level->logsVisits() => __('The activity log does not record album views (Settings), so guest visits are not counted.'),
                default => null,
            };
        @endphp
        @if ($note)
            <p class="og-muted og-calendar-note">{{ $note }}</p>
        @endif

        <div class="og-calendar-scroll" x-data x-init="$el.scrollLeft = $el.scrollWidth">
            <div class="og-calendar-grid" style="--weeks: {{ count($weeks) }}" role="img" aria-label="{{ $summary }}">
                {{-- Month labels: placed above the first week that starts in a new month. --}}
                @foreach ($weeks as $i => $week)
                    @if ($i === 0 || $week[0]['date']->month !== $weeks[$i - 1][0]['date']->month)
                        <span class="og-calendar-month" style="grid-column: {{ $i + 2 }}" aria-hidden="true">{{ $week[0]['date']->isoFormat('MMM') }}</span>
                    @endif
                @endforeach

                @foreach ([0, 2, 4] as $row)
                    <span class="og-calendar-weekday" style="grid-row: {{ $row + 2 }}" aria-hidden="true">{{ now()->startOfWeek()->addDays($row)->isoFormat('ddd') }}</span>
                @endforeach

                @foreach ($weeks as $i => $week)
                    @foreach ($week as $d => $day)
                        @unless ($day['future'])
                            <span class="og-cell" data-level="{{ $day['level'] }}"
                                  style="grid-column: {{ $i + 2 }}; grid-row: {{ $d + 2 }}"
                                  title="{{ __(':activity on :date', ['activity' => $day['count'] ? $unit($day['count']) : __('No activity'), 'date' => \App\Support\DateFormat::date($day['date'])]) }}"></span>
                        @endunless
                    @endforeach
                @endforeach
            </div>
        </div>

        <footer class="og-calendar-legend" aria-hidden="true">
            {{ __('Fewer') }}
            @foreach (range(0, 4) as $level)
                <span class="og-cell" data-level="{{ $level }}"></span>
            @endforeach
            {{ __('More') }}
        </footer>
    </x-filament::section>
</x-filament-widgets::widget>
