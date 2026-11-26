<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Group;
use App\Models\User;

/**
 * A password and its confirmation, as the forms post them.
 *
 * @return array{password: string, password_confirmation: string}
 */
function frontendPasswordFields(string $password = 'a-long-password'): array
{
    return ['password' => $password, 'password_confirmation' => $password];
}

/**
 * The fields of the sign-up form for $email.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frontendSignUpFields(string $email = 'grace@example.com', array $overrides = []): array
{
    return ['name' => 'Grace', 'email' => $email, ...frontendPasswordFields(), ...$overrides];
}

/** A new public album with the given title. */
function frontendPublicAlbum(string $title = 'Summer camp', array $attributes = []): Album
{
    return Album::factory()->visibility(Visibility::Public)->create(['title' => $title, ...$attributes]);
}

/** A new member (role "User") who may not create albums. */
function frontendMember(array $attributes = []): User
{
    return userWithRole(Role::User, $attributes);
}

/**
 * Someone who looks at $album, or null for a guest. $kind is one of: guest, member,
 * owner, owner who uploads, moderator, person it is shared with, member of a group it is shared with.
 */
function frontendAlbumViewer(string $kind, Album $album): ?User
{
    return match ($kind) {
        'guest' => null,
        'member' => userWithRole(Role::User),
        'owner' => $album->owner,
        'owner who uploads' => tap(userWithRole(Role::Uploader), fn (User $uploader) => $album->owner()->associate($uploader)->save()),
        'moderator' => userWithRole(Role::Moderator),
        'person it is shared with' => tap(userWithRole(Role::User), fn (User $user) => $album->sharedUsers()->attach($user)),
        'member of a group it is shared with' => tap(userWithRole(Role::User), function (User $user) use ($album) {
            $group = Group::factory()->create();
            $group->users()->attach($user);
            $album->sharedGroups()->attach($group);
        }),
    };
}
