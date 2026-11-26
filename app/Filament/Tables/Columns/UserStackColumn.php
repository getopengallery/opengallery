<?php

namespace App\Filament\Tables\Columns;

use Filament\Tables\Columns\ViewColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * A record's users (group members, role holders) as overlapping pictures,
 * each with its own name as tooltip and linked to the person for those who
 * may edit them, plus "+N" for the rest. Load the users
 * with self::withUsers() so only the first few are fetched per row.
 */
class UserStackColumn extends ViewColumn
{
    /** Pictures shown per row; the rest are counted. */
    public const SHOWN = 6;

    protected string $view = 'filament.tables.member-stack';

    protected ?string $emptyText = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Each picture links to its person, so the cell must not also be the row's link.
        $this->disabledClick();
    }

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    public function getEmptyText(): string
    {
        return $this->emptyText ?? __('No members');
    }

    /** Eager-loads the first users by name and counts all of them, however many there are. */
    public static function withUsers(Builder $query, string $relationship = 'users'): Builder
    {
        return $query
            ->withCount($relationship)
            ->with([$relationship => fn ($query) => $query->orderBy('name')->limit(self::SHOWN)]);
    }
}
