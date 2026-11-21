<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Support\AccountDeletion;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Illuminate\Database\Eloquent\Builder;

/**
 * The confirmation form for deleting users in the admin: when they own albums,
 * it asks who gets them (default: the person deleting). Without a new owner
 * the albums go to the trash (AccountDeletion::deleteKeepingAlbums()).
 */
class UserDeletionForm
{
    private const LISTED_TITLES = 5;

    /**
     * @param  list<int>  $userIds  the people being deleted
     * @return array<int, Text|Select>
     */
    public static function schema(array $userIds): array
    {
        $albums = AccountDeletion::ownedAlbums($userIds);

        if ($albums->isEmpty()) {
            return [];
        }

        $titles = $albums->take(self::LISTED_TITLES)->pluck('title')->join(', ');
        $more = $albums->count() - self::LISTED_TITLES;

        return [
            Text::make(__('Their albums (:count): :titles.', [
                'count' => $albums->count(),
                'titles' => $more > 0 ? __(':titles and :count more', ['titles' => $titles, 'count' => $more]) : $titles,
            ])),
            Select::make('new_owner_id')
                ->label(__('New owner of the albums'))
                ->placeholder(__('Nobody: move the albums to the trash'))
                ->helperText(__('Leave empty to move the albums to the trash. Albums in the trash have no owner until someone gives them one.'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => User::query()
                    ->whereKeyNot($userIds)
                    ->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orderBy('name')
                    ->limit(50)
                    ->pluck('name', 'id')
                    ->all())
                // People being deleted get no label, so Filament rejects them as new owners.
                ->getOptionLabelUsing(fn (mixed $value): ?string => in_array((int) $value, $userIds, true) ? null : User::query()->whereKey($value)->value('name'))
                ->default(in_array(auth()->id(), $userIds, true) ? null : auth()->id()),
        ];
    }

    /** Delete for one person (users table row, edit page): asks who gets their albums. */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->schema(fn (User $record): array => self::schema([$record->id]))
            ->using(fn (User $record, array $data): bool => self::delete($record, $data));
    }

    /** @param  array<string, mixed>  $data  the form state */
    public static function delete(User $user, array $data): bool
    {
        $newOwnerId = $data['new_owner_id'] ?? null;

        AccountDeletion::deleteKeepingAlbums($user, filled($newOwnerId) ? User::query()->findOrFail($newOwnerId) : null);

        return true;
    }
}
