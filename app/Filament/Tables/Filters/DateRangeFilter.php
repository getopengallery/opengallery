<?php

namespace App\Filament\Tables\Filters;

use App\Support\DateFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\FusedGroup;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The admin's one date filter: a "from – to" range in a single field, with
 * an indicator such as "Uploaded since Nov 3, 2026". Every list that filters
 * by date uses it, so they all look and read the same.
 *
 * DateRangeFilter::make('uploaded')->label(__('Uploaded'))->column('photos.created_at')
 */
class DateRangeFilter extends Filter
{
    protected string $column = 'created_at';

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(fn () => [
            FusedGroup::make([
                DatePicker::make('from')->label(__('From'))->placeholder(__('From'))->maxDate(fn ($get) => $get('until')),
                DatePicker::make('until')->label(__('To'))->placeholder(__('To'))->minDate(fn ($get) => $get('from')),
            ])
                ->label($this->getLabel())
                ->columns(2),
        ]);

        $this->query(fn (Builder $query, array $data) => $query
            ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate($this->column, '>=', $date))
            ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate($this->column, '<=', $date)));

        $this->indicateUsing(function (array $data): ?string {
            $from = filled($data['from'] ?? null) ? DateFormat::date(Carbon::parse($data['from'])) : null;
            $until = filled($data['until'] ?? null) ? DateFormat::date(Carbon::parse($data['until'])) : null;
            $label = $this->getLabel();

            return match (true) {
                $from && $until => __(':label :from – :until', ['label' => $label, 'from' => $from, 'until' => $until]),
                (bool) $from => __(':label since :date', ['label' => $label, 'date' => $from]),
                (bool) $until => __(':label until :date', ['label' => $label, 'date' => $until]),
                default => null,
            };
        });
    }

    /** The date or datetime column to filter (qualify it when the query joins). */
    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }
}
