<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;

/**
 * The periods the statistics page can show, ending today. A fixed list keeps
 * the number of cached results small, and the dashboard shares the same
 * cache entries when it shows the same period.
 */
enum StatisticsRange: int implements HasLabel
{
    case Week = 7;
    case Month = 30;
    case Quarter = 90;
    case Year = 365;

    public function getLabel(): string
    {
        return match ($this) {
            self::Week => __('Last 7 days'),
            self::Month => __('Last 30 days'),
            self::Quarter => __('Last 90 days'),
            self::Year => __('Last 12 months'),
        };
    }

    /** The first day of the period (today counts as one of its days). */
    public function start(): CarbonImmutable
    {
        return match ($this) {
            self::Year => CarbonImmutable::today()->startOfMonth()->subMonths(11),
            default => CarbonImmutable::today()->subDays($this->value - 1),
        };
    }

    /** How the period is split for charts: 'day', 'week' (starting Monday) or 'month'. */
    public function bucket(): string
    {
        return match ($this) {
            self::Week, self::Month => 'day',
            self::Quarter => 'week',
            self::Year => 'month',
        };
    }

    /**
     * The chart buckets of the period, oldest first.
     *
     * @return list<CarbonImmutable> the first day of each bucket
     */
    public function buckets(): array
    {
        $today = CarbonImmutable::today();
        $first = match ($this->bucket()) {
            'week' => $this->start()->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $this->start()->startOfMonth(),
            default => $this->start(),
        };

        $buckets = [];
        for ($date = $first; $date->lte($today); $date = $this->next($date)) {
            $buckets[] = $date;
        }

        return $buckets;
    }

    /** The first day of the bucket that $day falls in. */
    public function bucketOf(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this->bucket()) {
            'week' => $day->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $day->startOfMonth(),
            default => $day->startOfDay(),
        };
    }

    private function next(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this->bucket()) {
            'week' => $date->addWeek(),
            'month' => $date->addMonth(),
            default => $date->addDay(),
        };
    }
}
