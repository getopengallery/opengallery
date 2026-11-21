<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Filament\Notifications\EmailResults;
use App\Filament\RelationManagers\UsersRelationManager;
use App\Support\GroupNotices;
use Filament\Actions\AttachAction;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;

/** A group's members on its edit page. */
class MembersRelationManager extends UsersRelationManager
{
    /** @var array<int, list<int>> Albums the people being added could reach before (GroupNotices). */
    private array $accessBefore = [];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Members');
    }

    protected function addLabel(): string
    {
        return __('Add members');
    }

    protected function removeHeading(): string
    {
        return __('Remove from group');
    }

    protected function emptyHeading(): string
    {
        return __('No members yet');
    }

    /** Adding members can tell them about the albums the group gives them. */
    protected function configureAttachAction(AttachAction $action): AttachAction
    {
        return $action
            ->schema(fn (AttachAction $action): array => [
                $action->getRecordSelect(),
                Toggle::make('notify_group_access')
                    ->label(__('Tell people when they get access'))
                    ->helperText(__('They get one notification about the albums this group gives them, and an e-mail while e-mail works.'))
                    ->default(true),
            ])
            ->before(function (array $data): void {
                if ($data['notify_group_access'] ?? false) {
                    $this->accessBefore = GroupNotices::accessOf(self::addedIds($data));
                }
            })
            ->after(function (array $data): void {
                if ($data['notify_group_access'] ?? false) {
                    EmailResults::shareNotices(GroupNotices::notifyNew(self::addedIds($data), $this->accessBefore, auth()->user()));
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function addedIds(array $data): array
    {
        return array_values(array_map('intval', (array) ($data['recordId'] ?? [])));
    }
}
