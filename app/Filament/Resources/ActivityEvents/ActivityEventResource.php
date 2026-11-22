<?php

namespace App\Filament\Resources\ActivityEvents;

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\ActivityEvents\Pages\ManageActivityEvents;
use App\Filament\Tables\EmptyState;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\ActivityEvent;
use App\Support\DateFormat;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only activity log (System → Activity log).
 */
class ActivityEventResource extends Resource
{
    protected static ?string $model = ActivityEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'activity';

    public static function getModelLabel(): string
    {
        return __('activity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity log');
    }

    public static function getNavigationLabel(): string
    {
        return __('Activity log');
    }

    /** @return array<int, string> The log's entry stays highlighted on its settings page. */
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [static::getRouteBaseName().'.*', ActivityLogSettings::getRouteName()];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ViewActivity->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'album', 'subject']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime(DateFormat::DATE_TIME_SECONDS)
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('Who'))
                    ->state(fn (ActivityEvent $record) => $record->actorName())
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))),
                TextColumn::make('what')
                    ->label(__('What'))
                    ->state(fn (ActivityEvent $record) => trim($record->action().' '.($record->showsSubject() ? ($record->subjectLabel() ?? '') : '')))
                    ->badge(fn (ActivityEvent $record) => $record->type->isSecurity())
                    ->color(fn (ActivityEvent $record) => $record->type->isSecurity() ? 'warning' : null)
                    ->url(fn (ActivityEvent $record) => $record->subjectUrl())
                    ->wrap(),
                TextColumn::make('details')
                    ->label(__('Details'))
                    ->state(fn (ActivityEvent $record) => $record->details() ?: null)
                    ->listWithLineBreaks()
                    ->placeholder('–')
                    ->color('gray')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('Kind'))
                    ->placeholder(__('Everything'))
                    ->options(['visits' => __('Visits (logins, album views)'), 'changes' => __('Changes'), 'security' => __('Security')])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'visits' => $query->whereIn('type', [ActivityType::Login, ActivityType::AlbumView]),
                        'security' => $query->whereIn('type', ActivityType::security()),
                        'changes' => $query->whereNotIn('type', [ActivityType::Login, ActivityType::AlbumView, ...ActivityType::security()]),
                        default => $query,
                    }),
                SelectFilter::make('type')
                    ->label(__('What'))
                    ->multiple()
                    ->options(collect(ActivityType::cases())->mapWithKeys(fn (ActivityType $type) => [$type->value => $type->label()])),
                TernaryFilter::make('member')
                    ->label(__('Who'))
                    ->placeholder(__('Everyone'))
                    ->trueLabel(__('Members'))
                    ->falseLabel(__('Guests and system'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('user_id'),
                        false: fn (Builder $query) => $query->whereNull('user_id'),
                    ),
                SelectFilter::make('user')
                    ->label(__('Member'))
                    ->relationship('user', 'name')
                    ->searchable(),
                DateRangeFilter::make('period')
                    ->label(__('When'))
                    ->column('created_at'),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->tap(fn (Table $table) => EmptyState::apply(
                $table,
                __('Nothing recorded yet'),
                __('Logins, album views and changes appear here, as far as the log settings record them.'),
                Heroicon::OutlinedClock,
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActivityEvents::route('/'),
        ];
    }
}
