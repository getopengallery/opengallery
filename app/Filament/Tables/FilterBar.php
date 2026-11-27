<?php

namespace App\Filament\Tables;

use App\Filament\Tables\Filters\DateRangeFilter;
use App\Filament\Tables\Filters\TextFilter;
use App\Support\DateFormat;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Mechanisms\ExtendBlade\ExtendBlade;
use LogicException;
use WeakMap;

/**
 * The admin's filter bar: each table filter is a chip above the table
 * ("Album: Sea ×"), and the chip opens a small popover to pick values.
 * Primary filters always show a chip; the others are under "More filters"
 * and get a chip once they are set. Filament's own filter panel is hidden,
 * but it still applies the filters, so filters stay plain Filament filters.
 *
 * Supported filters: SelectFilter (single, multiple, relationship), TernaryFilter,
 * DateRangeFilter, TextFilter and toggle Filters without a form.
 *
 * FilterBar::configure($table, primary: ['albums', 'taken'])->filters([...]);
 */
class FilterBar
{
    /** @var WeakMap<Table, list<string>>|null */
    private static ?WeakMap $tables = null;

    /** Above this many options a popover gets a search field (relationship filters always have one). */
    public const SEARCH_THRESHOLD = 7;

    /** Most related records a relationship chip lists (the most used ones, plus any selected). */
    public const OPTION_LIMIT = 100;

    /**
     * Shows the table's filters as chips.
     *
     * @param  list<string>  $primary  names of the filters that always show a chip, in this order
     */
    public static function configure(Table $table, array $primary = []): Table
    {
        self::$tables ??= new WeakMap;
        self::$tables[$table] = array_values($primary);

        return $table
            ->filtersLayout(FiltersLayout::Hidden)
            ->deferFilters(false);
    }

    public static function uses(Table $table): bool
    {
        return isset(self::$tables[$table]);
    }

    /** The table of the Livewire component being rendered, if it uses the filter bar. */
    public static function currentTable(): ?Table
    {
        $component = ExtendBlade::currentRendering();

        if (! $component instanceof HasTable) {
            return null;
        }

        $table = $component->getTable();

        return self::uses($table) && $table->getFilters() !== [] ? $table : null;
    }

    /** Render hook after the table toolbar: the chips. */
    public static function render(): ?HtmlString
    {
        $table = self::currentTable();

        if ($table === null) {
            return null;
        }

        return new HtmlString(view('filament.tables.filter-bar', [
            'chips' => self::chips($table),
            'resultCount' => self::resultCount($table->getLivewire()),
        ])->render());
    }

    /** Render hook in place of Filament's "Active filters" row: nothing, the chips already say it. */
    public static function hideIndicators(): ?HtmlString
    {
        return self::currentTable() ? new HtmlString('<span hidden></span>') : null;
    }

    /**
     * One entry per visible filter, primary filters first.
     *
     * @return list<array{name: string, label: string, kind: string, primary: bool, active: bool, summary: ?string, multiple: bool, options: array<string, array{label: string, count: ?int}>, presets: array<string, array{label: string, from: string, until: string}>, limited: bool, searchable: bool, state: array<string, mixed>}>
     */
    public static function chips(Table $table): array
    {
        $primary = self::$tables[$table] ?? [];
        $chips = [];

        foreach ($table->getFilters() as $name => $filter) {
            $chips[$name] = self::chip($table, $filter, in_array($name, $primary, true));
        }

        $ordered = [];

        foreach ($primary as $name) {
            if (isset($chips[$name])) {
                $ordered[] = $chips[$name];
                unset($chips[$name]);
            }
        }

        return [...$ordered, ...array_values($chips)];
    }

    /**
     * @return array{name: string, label: string, kind: string, primary: bool, active: bool, summary: ?string, multiple: bool, options: array<string, array{label: string, count: ?int}>, presets: array<string, array{label: string, from: string, until: string}>, limited: bool, searchable: bool, state: array<string, mixed>}
     */
    private static function chip(Table $table, BaseFilter $filter, bool $primary): array
    {
        $state = $filter->getState();
        $chip = [
            'name' => $filter->getName(),
            'label' => (string) $filter->getLabel(),
            'kind' => 'select',
            'primary' => $primary,
            'active' => false,
            'summary' => null,
            'multiple' => false,
            'options' => [],
            'presets' => [],
            'limited' => false,
            'searchable' => false,
            'state' => $state,
        ];

        if ($filter instanceof DateRangeFilter) {
            $from = filled($state['from'] ?? null) ? (string) $state['from'] : null;
            $until = filled($state['until'] ?? null) ? (string) $state['until'] : null;
            $chip['kind'] = 'date';
            $chip['presets'] = self::datePresets();
            $chip['active'] = $from !== null || $until !== null;
            $chip['summary'] = $chip['active'] ? self::dateSummary($from, $until, $chip['presets']) : null;

            return $chip;
        }

        if ($filter instanceof TextFilter) {
            $text = TextFilter::text($state);
            $chip['kind'] = 'text';
            $chip['active'] = $text !== null;
            $chip['summary'] = $text !== null ? __('":text"', ['text' => $text]) : null;

            return $chip;
        }

        if ($filter instanceof TernaryFilter) {
            $chip['options'] = [
                '1' => ['label' => (string) $filter->getTrueLabel(), 'count' => null],
                '0' => ['label' => (string) $filter->getFalseLabel(), 'count' => null],
            ];

            return self::withSelection($chip, $state);
        }

        if ($filter instanceof SelectFilter) {
            $chip['multiple'] = $filter->isMultiple();
            if ($filter->hasRelationship()) {
                [$chip['options'], $chip['limited']] = self::relationshipOptions($table, $filter, self::selectedValues($state));
            } else {
                $chip['options'] = array_map(fn ($label): array => ['label' => (string) $label, 'count' => null], $filter->getOptions());
            }

            // Lists of records (albums, people, tags) grow over time, so they can always be searched.
            $chip['searchable'] = $filter->hasRelationship() || count($chip['options']) > self::SEARCH_THRESHOLD;

            return self::withSelection($chip, $state);
        }

        if ($filter instanceof Filter && ! $filter->hasForm()) {
            $chip['kind'] = 'toggle';
            $chip['active'] = (bool) ($state['isActive'] ?? false);

            return $chip;
        }

        throw new LogicException('The filter bar cannot show the filter ['.$filter->getName().'] of type ['.$filter::class.'].');
    }

    /**
     * @param  array<string, mixed>  $chip
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function withSelection(array $chip, array $state): array
    {
        $selected = self::selectedValues($state);
        $labels = array_values(array_filter(array_map(fn (string $value): ?string => $chip['options'][$value]['label'] ?? null, $selected)));

        $chip['active'] = $selected !== [];
        $chip['summary'] = match (count($labels)) {
            0 => null,
            1 => $labels[0],
            default => __(':first +:count', ['first' => $labels[0], 'count' => count($labels) - 1]),
        };

        return $chip;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private static function selectedValues(array $state): array
    {
        $values = array_key_exists('values', $state) ? (array) $state['values'] : [$state['value'] ?? null];

        return array_values(array_map('strval', array_filter($values, fn ($value): bool => filled($value))));
    }

    /**
     * Related records that occur in the table, with how many rows each has,
     * ordered like the filter's own options, and whether only the most used
     * OPTION_LIMIT are listed. Uses the filter's relationship query, so access
     * scopes (such as "albums you can list") still apply.
     *
     * @param  list<string>  $selected
     * @return array{0: array<string, array{label: string, count: ?int}>, 1: bool}
     */
    private static function relationshipOptions(Table $table, SelectFilter $filter, array $selected): array
    {
        $allCounts = self::relationshipCounts($table, $filter);
        $counts = $allCounts;
        $limited = count($counts) > self::OPTION_LIMIT;

        if ($limited) {
            arsort($counts);
            $counts = array_slice($counts, 0, self::OPTION_LIMIT, preserve_keys: true);
        }

        $related = $filter->getRelationshipQuery();
        $key = $filter->getQualifiedRelatedKeyNameForRelationship($related);
        $keys = array_values(array_unique([...array_keys($counts), ...$selected]));

        $options = [];

        if ($filter->hasEmptyRelationshipOption()) {
            $options['__empty'] = ['label' => $filter->getEmptyRelationshipOptionLabel(), 'count' => null];
        }

        if ($keys === []) {
            return [$options, $limited];
        }

        $labels = $related->whereIn($key, $keys)->pluck($related->qualifyColumn($filter->getRelationshipTitleAttribute()), $key);

        foreach ($labels as $id => $label) {
            $options[(string) $id] = ['label' => (string) $label, 'count' => $allCounts[(string) $id] ?? 0];
        }

        return [$options, $limited];
    }

    /**
     * How many of the table's rows (before filters and search) point to each
     * related record, keyed by related key. Only BelongsTo and BelongsToMany.
     *
     * @return array<string, int>
     */
    private static function relationshipCounts(Table $table, SelectFilter $filter): array
    {
        $relationship = $filter->getRelationship();
        $base = $table->getQuery()?->reorder();

        if (! $base instanceof Builder) {
            return [];
        }

        $rows = match (true) {
            $relationship instanceof BelongsToMany => DB::table($relationship->getTable())
                ->whereIn($relationship->getQualifiedForeignPivotKeyName(), $base->toBase()->select($base->getModel()->getQualifiedKeyName()))
                ->groupBy($relationship->getQualifiedRelatedPivotKeyName())
                ->select($relationship->getQualifiedRelatedPivotKeyName().' as og_key')
                ->selectRaw('count(*) as og_count')
                ->pluck('og_count', 'og_key'),
            $relationship instanceof BelongsTo => $base->toBase()
                ->whereNotNull($relationship->getQualifiedForeignKeyName())
                ->groupBy($relationship->getQualifiedForeignKeyName())
                ->select($relationship->getQualifiedForeignKeyName().' as og_key')
                ->selectRaw('count(*) as og_count')
                ->pluck('og_count', 'og_key'),
            default => collect(),
        };

        return $rows->mapWithKeys(fn ($count, $key): array => [(string) $key => (int) $count])->all();
    }

    /**
     * Quick picks for date filters, from–until as Y-m-d.
     *
     * @return array<string, array{label: string, from: string, until: string}>
     */
    public static function datePresets(): array
    {
        $today = Carbon::today();

        return [
            'last7' => ['label' => __('Last 7 days'), 'from' => $today->copy()->subDays(6)->toDateString(), 'until' => $today->toDateString()],
            'last30' => ['label' => __('Last 30 days'), 'from' => $today->copy()->subDays(29)->toDateString(), 'until' => $today->toDateString()],
            'thisYear' => ['label' => (string) $today->year, 'from' => $today->copy()->startOfYear()->toDateString(), 'until' => $today->copy()->endOfYear()->toDateString()],
            'lastYear' => ['label' => (string) ($today->year - 1), 'from' => $today->copy()->subYear()->startOfYear()->toDateString(), 'until' => $today->copy()->subYear()->endOfYear()->toDateString()],
        ];
    }

    /** @param  array<string, array{label: string, from: string, until: string}>  $presets */
    private static function dateSummary(?string $from, ?string $until, array $presets): string
    {
        foreach ($presets as $preset) {
            if ($preset['from'] === $from && $preset['until'] === $until) {
                return $preset['label'];
            }
        }

        $from = $from !== null ? DateFormat::date(Carbon::parse($from)) : null;
        $until = $until !== null ? DateFormat::date(Carbon::parse($until)) : null;

        return match (true) {
            $from !== null && $until !== null => $from.' – '.$until,
            $from !== null => __('since :date', ['date' => $from]),
            default => __('until :date', ['date' => $until]),
        };
    }

    /** Rows matching the filters, for the "Show N results" button on phones; null when unknown. */
    private static function resultCount(HasTable $livewire): ?int
    {
        $records = $livewire->getTableRecords();

        return match (true) {
            $records instanceof LengthAwarePaginator => $records->total(),
            $records instanceof Collection => $records->count(),
            default => null,
        };
    }
}
