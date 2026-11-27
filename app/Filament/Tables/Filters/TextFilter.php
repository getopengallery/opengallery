<?php

namespace App\Filament\Tables\Filters;

use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin's one free-text filter: a single "contains" field, with an
 * indicator such as 'File name contains "IMG_20"'. The filter bar shows it
 * as a chip with a text field.
 *
 * TextFilter::make('file_name')->label(__('File name'))
 *     ->matchUsing(fn (Builder $query, string $text) => $query->where(...))
 */
class TextFilter extends Filter
{
    protected ?Closure $matchUsing = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(fn () => [
            TextInput::make('value')->label($this->getLabel()),
        ]);

        $this->query(function (Builder $query, array $data): Builder {
            $text = self::text($data);

            if ($text === null || $this->matchUsing === null) {
                return $query;
            }

            return $this->evaluate($this->matchUsing, ['query' => $query, 'text' => $text]) ?? $query;
        });

        $this->indicateUsing(fn (array $data): ?string => ($text = self::text($data)) !== null
            ? __(':label contains ":text"', ['label' => $this->getLabel(), 'text' => $text])
            : null);
    }

    /** Narrows the query to rows matching the typed text: fn (Builder $query, string $text). */
    public function matchUsing(Closure $callback): static
    {
        $this->matchUsing = $callback;

        return $this;
    }

    /**
     * The typed text without surrounding spaces, or null when empty.
     *
     * @param  array<string, mixed>  $data
     */
    public static function text(array $data): ?string
    {
        $text = trim((string) ($data['value'] ?? ''));

        return $text === '' ? null : $text;
    }
}
