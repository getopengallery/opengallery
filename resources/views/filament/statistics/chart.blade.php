{{--
    Bars per period bucket with a headline figure (statistics page).
    $buckets: list of arrays with 'start' and the values named in $parts;
    $parts: list of [key, class (is-members/is-guests/is-downloads), label], stacked bottom-up;
    $value / $label: the headline; $period: StatisticsRange; $current: whether the last bucket is still running.
--}}
@php
    $total = fn (array $bucket) => array_sum(array_map(fn (array $part) => $bucket[$part[0]], $parts));
    $max = max(1, ...array_map($total, $buckets));
    $date = fn (\Carbon\CarbonImmutable $start) => match ($period->bucket()) {
        'month' => $start->format('m.Y'),
        'week' => __('Week of :date', ['date' => \App\Support\DateFormat::date($start)]),
        default => \App\Support\DateFormat::date($start),
    };
    $lastIndex = count($buckets) - 1;
@endphp

<figure class="og-trend">
    <figcaption class="og-trend-head">
        <span class="og-trend-value og-num">{{ \Illuminate\Support\Number::format($value) }}</span>
        <span class="og-trend-label">{{ $label }}</span>
        <span class="og-trend-change">{{ $change ?? '' }}</span>
    </figcaption>

    <div @class(['og-trend-chart', 'is-dense' => count($buckets) > 20]) style="--cols: {{ count($buckets) }}" aria-hidden="true">
        @foreach ($buckets as $i => $bucket)
            <span @class(['og-trend-col', 'is-current' => ($current ?? true) && $i === $lastIndex])
                  title="{{ $date($bucket['start']) }}: {{ implode(', ', array_map(fn (array $part) => $part[2].' '.\Illuminate\Support\Number::format($bucket[$part[0]]), $parts)) }}">
                @foreach (array_reverse($parts) as [$key, $class])
                    @if ($bucket[$key] > 0)
                        <span class="og-trend-bar {{ $class }}" style="--share: {{ round($bucket[$key] / $max * 100, 1) }}%"></span>
                    @endif
                @endforeach
            </span>
        @endforeach
    </div>
    <div class="og-trend-axis" aria-hidden="true"><span>{{ $date($buckets[0]['start']) }}</span><span>{{ $date($buckets[$lastIndex]['start']) }}</span></div>

    @if (count($parts) > 1)
        <p class="og-trend-legend">
            @foreach ($parts as [$key, $class, $partLabel])
                <span><i class="og-swatch {{ $class }}"></i>{{ $partLabel }}</span>
            @endforeach
        </p>
    @endif

    <table class="og-sr-only">
        <caption>{{ $label }}</caption>
        <thead><tr><th scope="col">{{ __('Period') }}</th>@foreach ($parts as [$key, $class, $partLabel])<th scope="col">{{ $partLabel }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($buckets as $bucket)
                <tr><th scope="row">{{ $date($bucket['start']) }}</th>@foreach ($parts as [$key])<td>{{ $bucket[$key] }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
</figure>
