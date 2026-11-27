<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Notifications\EmailResults;
use App\Filament\Resources\Users\Schemas\UserDeletionForm;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\GroupNotices;
use App\Support\Invitations;
use App\Support\SecurityNotices;
use App\Support\StorageQuota;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<int, list<int>>|null Albums the person could reach before this save, when they are to be told (GroupNotices). */
    private ?array $groupAccessBefore = null;

    /**
     * The picture path is a hidden attribute (kept out of the activity log), so
     * Filament does not fill it by itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['avatar_path'] = $this->getRecord()->avatar_path;
        $data['storage_unlimited'] = $this->getRecord()->storage_unlimited;
        $data['storage_quota_gb'] = StorageQuota::bytesToGb($this->getRecord()->storage_quota_bytes);

        return $data;
    }

    protected function beforeSave(): void
    {
        if ($this->data['notify_group_access'] ?? false) {
            $this->groupAccessBefore = GroupNotices::accessOf([$this->getRecord()->id]);
        }
    }

    /**
     * "E-mail verified at" is not mass assignable on User, so it is saved here.
     * A new password or e-mail address is a security notice to the person.
     */
    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $passwordChanged = $user->wasChanged('password');
        $oldEmail = $user->wasChanged('email') ? ($user->getPrevious()['email'] ?? null) : null;

        $user->forceFill([
            'email_verified_at' => $this->data['email_verified_at'] ?? null,
            ...$this->storageAttributes($user),
        ])->save();

        if ($passwordChanged) {
            SecurityNotices::passwordChanged($user, auth()->user());
        }

        if ($oldEmail !== null) {
            SecurityNotices::emailChanged($user, $oldEmail, auth()->user());
        }

        if ($this->groupAccessBefore !== null) {
            EmailResults::shareNotices(GroupNotices::notifyNew([$user->id], $this->groupAccessBefore, auth()->user()));
        }
    }

    /**
     * The storage columns from the form; a limit whose GB value did not change keeps its exact bytes.
     *
     * @return array{storage_unlimited?: bool, storage_quota_bytes?: ?int}
     */
    private function storageAttributes(User $user): array
    {
        $attributes = UserForm::storageAttributes($this->data);
        $bytes = $attributes['storage_quota_bytes'] ?? null;

        if ($bytes !== null && StorageQuota::bytesToGb($user->storage_quota_bytes) === StorageQuota::bytesToGb($bytes)) {
            $attributes['storage_quota_bytes'] = $user->storage_quota_bytes;
        }

        return $attributes;
    }

    protected function getHeaderActions(): array
    {
        return [
            UsersTable::approveAction(),
            Action::make('sendInvitation')
                ->label(__('Send password link'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(fn (User $record): string => __('Send :name a link to choose a password?', ['name' => $record->name]))
                ->modalDescription(__('For people who were invited but never chose a password, or who are locked out. Earlier links stop working; the current password keeps working until a new one is chosen.'))
                ->modalSubmitActionLabel(__('Send link'))
                ->action(fn (User $record) => EmailResults::invitation($record, Invitations::send($record, auth()->user()))),
            UserDeletionForm::deleteAction(),
        ];
    }
}
