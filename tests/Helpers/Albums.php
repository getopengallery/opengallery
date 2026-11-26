<?php

use App\Enums\CollaboratorRole;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/** Gives $user (a new member without admin access by default) a direct role on $album. */
function addCollaborator(Album $album, CollaboratorRole $role, ?User $user = null): User
{
    $user ??= User::factory()->create();
    $album->collaborators()->attach($user, ['role' => $role->value]);

    return $user;
}

/** Gives a new group a role on $album; $members join it. */
function addGroupCollaborator(Album $album, CollaboratorRole $role, User ...$members): Group
{
    $group = Group::factory()->create();
    $group->users()->attach(array_map(fn (User $member) => $member->id, $members));
    $album->collaboratorGroups()->attach($group, ['role' => $role->value]);

    return $group;
}

/**
 * A tagged public parent, an album two levels below it and a photo there.
 *
 * @param  array<string, mixed>  $parent
 * @return array{0: Album, 1: Album, 2: Photo}
 */
function albumTree(bool $passesOn, array $parent = []): array
{
    $top = tagged(Album::factory()->visibility(Visibility::Public)->create([...$parent, 'tags_apply_to_sub_albums' => $passesOn]), 'Family');
    $middle = Album::factory()->visibility(Visibility::Public)->create(['parent_id' => $top->id]);
    $bottom = Album::factory()->visibility(Visibility::Public)->create(['parent_id' => $middle->id]);

    return [$top, $bottom, Photo::factory()->inAlbum($bottom)->create()];
}

/** The Livewire test of the album edit page for $album. */
function albumPage(Album $album): mixed
{
    return Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()]);
}

/** The Livewire test of the Photos section on the edit page of $album. */
function albumPhotosSection(Album $album): mixed
{
    return Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class]);
}

/** Creates a sub-album "Day 1" of $parent while signed in as $creator. */
function subAlbumCreatedBy(User $creator, Album $parent): Album
{
    test()->actingAs($creator);

    return Album::create(['title' => 'Day 1', 'parent_id' => $parent->id, 'owner_id' => $parent->owner_id]);
}

/**
 * A signed-in super admin "Ada", a group and a restricted album shared with the group.
 *
 * @return array{0: User, 1: Group, 2: Album}
 */
function groupWithAlbum(): array
{
    $ada = userWithRole(Role::SuperAdmin, ['name' => 'Ada']);
    $group = Group::factory()->create();
    $album = Album::factory()->visibility(Visibility::Restricted)->create();
    $album->sharedGroups()->attach($group);
    test()->actingAs($ada);

    return [$ada, $group, $album];
}

/**
 * Attaches $people to $group through the group's Members section; $tell sends the access notice.
 *
 * @param  list<User>  $people
 */
function addMembers(Group $group, array $people, bool $tell = true): void
{
    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditGroup::class])
        ->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => collect($people)->pluck('id')->all(),
            'notify_group_access' => $tell,
        ])
        ->assertHasNoFormErrors();
}
