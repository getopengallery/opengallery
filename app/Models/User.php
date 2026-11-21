<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Pivots\AlbumCollaborator;
use App\Models\Pivots\AlbumUser;
use App\Models\Pivots\GroupUser;
use App\Models\Pivots\UserRole;
use App\Notifications\AccountCreated;
use App\Support\AlbumCollaboration;
use App\Support\Avatars;
use App\Support\Inbox;
use App\Support\Locales;
use App\Support\Mailing;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'avatar_path', 'locale'])]
#[Hidden(['password', 'remember_token', 'avatar_path'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasRoles {
        roles as protected permissionPackageRoles;
    }

    /** A replaced or removed profile picture is deleted from storage, notifications go with the account. */
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('avatar_path')) {
                Avatars::delete($user->getOriginal('avatar_path'));
            }
        });

        static::deleted(function (User $user) {
            Avatars::delete($user->avatar_path);

            // Their own notifications, and the notices about their account to people who manage users.
            $user->notifications()->delete();
            Inbox::forget(AccountCreated::class, 'account', $user->id);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'awaiting_approval' => 'boolean',
            'dashboard_layout' => 'array',
            'password' => 'hashed',
        ];
    }

    /**
     * The confirmation link goes out only while e-mail works (otherwise the
     * address is not asked for, see EnsureAccountIsActive), and a failing mail
     * server never stops a sign-up or a profile change.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (Mailing::delivers()) {
            Mailing::send(fn () => parent::sendEmailVerificationNotification());
        }
    }

    /**
     * Whether this person must confirm their e-mail address before using the
     * site: only while e-mail works, so nobody is locked out by a missing setup.
     */
    public function mustConfirmEmail(): bool
    {
        return ! $this->hasVerifiedEmail() && Mailing::delivers();
    }

    /**
     * Language for e-mails and notifications sent to this user: their own
     * choice, else the site default.
     */
    public function preferredLocale(): string
    {
        return Locales::for($this);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return Avatars::url($this);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->opensAdmin();
    }

    /**
     * Whether this person may enter the admin: with "Open the admin area", or
     * with a collaborator role, which only opens the Albums section.
     */
    public function opensAdmin(): bool
    {
        return $this->can(Permission::AccessAdmin->value) || AlbumCollaboration::albumIdsFor($this) !== [];
    }

    /**
     * Whether this user may edit or delete $other: only when every role $other
     * holds is one this user could give (Role::assignableBy()), so managing
     * users never reaches people with more rights than oneself.
     */
    public function canManage(User $other): bool
    {
        return $other->roles()
            ->whereNotIn('roles.id', Role::query()->assignableBy($this)->select('roles.id'))
            ->doesntExist();
    }

    /**
     * Spatie's roles relation with a custom pivot, so role changes reach the activity log.
     */
    public function roles(): BelongsToMany
    {
        return $this->permissionPackageRoles()->using(UserRole::class);
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->using(GroupUser::class);
    }

    /** @return HasMany<Album, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class, 'owner_id');
    }

    /** Albums explicitly shared with this user. */
    /** @return BelongsToMany<Album, $this> */
    public function sharedAlbums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class)->using(AlbumUser::class);
    }

    /** @return BelongsToMany<Album, $this> albums this user was directly invited to work on */
    public function collaborationAlbums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class, 'album_collaborators')
            ->using(AlbumCollaborator::class)
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }
}
