@php
    $note = match (true) {
        ! $level->logsLogins() => __('The activity log does not record logins and visits (Settings), so new weeks stay empty.'),
        ! $level->logsVisits() => __('The activity log does not record album views (Settings), so only logins are counted.'),
        default => null,
    };
    $lastIndex = count($weeks) - 1;
    $week = fn (array $w) => \App\Support\DateFormat::date($w['start']);
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Trends')" :description="__('Weeks start on Monday. The current week is still running and drawn hatched.')">
        @if ($note)
            <p class="og-muted og-calendar-note">{{ $note }}</p>
        @endif

        <div class="og-trends">
            <figure class="og-trend">
                <figcaption class="og-trend-head">
                    <span class="og-trend-value og-num">{{ \Illuminate\Support\Number::format($views['value']) }}</span>
                    <span class="og-trend-label">{{ trans_choice('album view last week|album views last week', $views['value']) }}</span>
                    <span class="og-trend-change">{{ $views['change'] ?? '' }}</span>
                </figcaption>

                <div class="og-trend-chart" aria-hidden="true">
                    @foreach ($weeks as $i => $w)
                        <span @class(['og-trend-col', 'is-current' => $i === $lastIndex]) title="{{ __('Week of :date: :members by members, :guests by guests', ['date' => $week($w), 'members' => \Illuminate\Support\Number::format($w['memberViews']), 'guests' => \Illuminate\Support\Number::format($w['guestViews'])]) }}">
                            @if ($w['guestViews'] > 0)
                                <span class="og-trend-bar is-guests" style="--share: {{ round($w['guestViews'] / $maxViews * 100, 1) }}%"></span>
                            @endif
                            @if ($w['memberViews'] > 0)
                                <span class="og-trend-bar is-members" style="--share: {{ round($w['memberViews'] / $maxViews * 100, 1) }}%"></span>
                            @endif
                        </span>
                    @endforeach
                </div>
                <div class="og-trend-axis" aria-hidden="true"><span>{{ \App\Support\DateFormat::date($firstWeek) }}</span><span>{{ __('This week') }}</span></div>

                <p class="og-trend-legend">
                    <span><i class="og-swatch is-members"></i>{{ __('Members') }}</span>
                    <span><i class="og-swatch is-guests"></i>{{ __('Guests') }}</span>
                    @if ($guestShare !== null)
                        <span class="og-muted">{{ __(':percent% of last week’s views were by guests', ['percent' => $guestShare]) }}</span>
                    @endif
                </p>
            </figure>

            <figure class="og-trend">
                <figcaption class="og-trend-head">
                    <span class="og-trend-value og-num">{{ \Illuminate\Support\Number::format($logins['value']) }}</span>
                    <span class="og-trend-label">{{ trans_choice('login last week|logins last week', $logins['value']) }}</span>
                    <span class="og-trend-change">{{ $logins['change'] ?? '' }}</span>
                </figcaption>

                <div class="og-trend-chart" aria-hidden="true">
                    @foreach ($weeks as $i => $w)
                        <span @class(['og-trend-col', 'is-current' => $i === $lastIndex]) title="{{ __('Week of :date: :count logins', ['date' => $week($w), 'count' => \Illuminate\Support\Number::format($w['logins'])]) }}">
                            @if ($w['logins'] > 0)
                                <span class="og-trend-bar is-members" style="--share: {{ round($w['logins'] / $maxLogins * 100, 1) }}%"></span>
                            @endif
                        </span>
                    @endforeach
                </div>
                <div class="og-trend-axis" aria-hidden="true"><span>{{ \App\Support\DateFormat::date($firstWeek) }}</span><span>{{ __('This week') }}</span></div>
            </figure>
        </div>

        <table class="og-sr-only">
            <caption>{{ __('Trends') }}</caption>
            <thead><tr><th scope="col">{{ __('Week of') }}</th><th scope="col">{{ __('Views by members') }}</th><th scope="col">{{ __('Views by guests') }}</th><th scope="col">{{ __('Logins') }}</th></tr></thead>
            <tbody>
                @foreach ($weeks as $w)
                    <tr><th scope="row">{{ $week($w) }}</th><td>{{ $w['memberViews'] }}</td><td>{{ $w['guestViews'] }}</td><td>{{ $w['logins'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-widgets::widget>
