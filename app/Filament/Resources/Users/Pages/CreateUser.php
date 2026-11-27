<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Notifications\EmailResults;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Invitations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

/**
 * New users either get a password from the admin or an invitation
 * ("Send an invitation", on when e-mail works) to choose their own.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** "E-mail verified at" from the form (not mass assignable on User). */
    private mixed $verifiedAt = null;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Invited people choose their password; until then nobody can log in with this one.
        $data['password'] ??= Str::random(64);
        $this->verifiedAt = $data['email_verified_at'] ?? null;

        return $data;
    }

    protected function afterCreate(): void
    {
        // People added by an administrator count as confirmed unless the field was cleared.
        $this->getRecord()->forceFill([
            'email_verified_at' => $this->verifiedAt,
            ...UserForm::storageAttributes($this->data),
        ])->saveQuietly();

        if ($this->data['send_invitation'] ?? false) {
            /** @var User $user */
            $user = $this->getRecord();
            EmailResults::invitation($user, Invitations::send($user, auth()->user()));
        }
    }
}
