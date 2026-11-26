<?php

use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Photos\Tables\PhotosTable;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\User;
use App\Support\AlbumAccess;
use App\Support\AlbumCollaboration;
use App\Support\RequestMemo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

describe('what each role may do', function () {
    it('gives each role exactly its abilities', function (CollaboratorRole $role, array $allowed) {
        $album = Album::factory()->create();
        $user = addCollaborator($album, $role);

        $abilities = collect(['view', 'upload', 'removePhotos', 'createSubAlbum', 'editSettings', 'viewCollaborators', 'leave'])
            ->mapWithKeys(fn (string $ability) => [$ability => $user->can($ability, $album)])
            ->filter()
            ->keys()
            ->all();

        expect($abilities)->toEqualCanonicalizing($allowed);
    })->with([
        'contributor' => [CollaboratorRole::Contributor, ['view', 'upload', 'removePhotos', 'viewCollaborators', 'leave']],
        'organizer' => [CollaboratorRole::Organizer, ['view', 'upload', 'removePhotos', 'createSubAlbum', 'viewCollaborators', 'leave']],
        'editor' => [CollaboratorRole::Editor, ['view', 'upload', 'removePhotos', 'createSubAlbum', 'editSettings', 'viewCollaborators', 'leave']],
    ]);

    it('never lets a role manage, edit, trash or delete the album', function (CollaboratorRole $role) {
        $album = Album::factory()->create();
        $user = addCollaborator($album, $role);

        foreach (['update', 'delete', 'restore', 'forceDelete', 'manageCollaborators', 'searchDirectory'] as $ability) {
            expect($user->can($ability, $album))->toBeFalse($ability);
        }
    })->with(CollaboratorRole::cases());

    it('limits contributors to removing their own uploads', function () {
        $album = Album::factory()->create();
        $contributor = addCollaborator($album, CollaboratorRole::Contributor);
        $own = Photo::factory()->inAlbum($album)->create(['uploader_id' => $contributor->id]);
        $others = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);

        expect($contributor->can('removePhotos', [$album, $own]))->toBeTrue()
            ->and($contributor->can('removePhotos', [$album, $others]))->toBeFalse();
    });

    it('lets organizers remove any photo', function () {
        $album = Album::factory()->create();
        $organizer = addCollaborator($album, CollaboratorRole::Organizer);
        $others = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);

        expect($organizer->can('removePhotos', [$album, $others]))->toBeTrue();
    });

    it('lets only owners with the permission and staff manage collaborators', function () {
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        $plainOwnerAlbum = Album::factory()->create();
        $moderator = tap(User::factory()->create())->assignRole(Role::Moderator->value);

        expect($owner->can('manageCollaborators', $album))->toBeTrue()
            ->and($plainOwnerAlbum->owner->can('manageCollaborators', $plainOwnerAlbum))->toBeFalse()
            ->and($moderator->can('manageCollaborators', $album))->toBeTrue();
    });

    it('seeds the invite permission for moderators and uploaders only', function (Role $role, bool $expected) {
        $user = tap(User::factory()->create())->assignRole($role->value);

        expect($user->can(Permission::InviteCollaborators->value))->toBe($expected);
    })->with([
        'admin' => [Role::Admin, true],
        'moderator' => [Role::Moderator, true],
        'uploader' => [Role::Uploader, true],
        'user' => [Role::User, false],
    ]);
});

describe('working out a role', function () {
    it('takes the highest of direct, group and inherited roles', function (CollaboratorRole $direct, CollaboratorRole $group, CollaboratorRole $parent, CollaboratorRole $expected) {
        $root = Album::factory()->create();
        $album = Album::factory()->for($root->owner, 'owner')->create(['parent_id' => $root->id]);
        $user = addCollaborator($album, $direct);
        addGroupCollaborator($album, $group, $user);
        addCollaborator($root, $parent, $user);

        expect($album->collaboratorRole($user))->toBe($expected);
    })->with([
        'direct wins' => [CollaboratorRole::Editor, CollaboratorRole::Contributor, CollaboratorRole::Organizer, CollaboratorRole::Editor],
        'group wins' => [CollaboratorRole::Contributor, CollaboratorRole::Editor, CollaboratorRole::Organizer, CollaboratorRole::Editor],
        'parent wins' => [CollaboratorRole::Contributor, CollaboratorRole::Organizer, CollaboratorRole::Editor, CollaboratorRole::Editor],
    ]);

    it('applies a role to every album below', function () {
        $root = Album::factory()->create();
        $child = Album::factory()->for($root->owner, 'owner')->create(['parent_id' => $root->id]);
        $grandchild = Album::factory()->for($root->owner, 'owner')->create(['parent_id' => $child->id]);
        $sibling = Album::factory()->for($root->owner, 'owner')->create();
        $user = addCollaborator($root, CollaboratorRole::Organizer);

        expect($grandchild->collaboratorRole($user))->toBe(CollaboratorRole::Organizer)
            ->and($sibling->collaboratorRole($user))->toBeNull();
    });

    it('ends inherited access in the same request when an album moves out of the shared parent', function () {
        $root = Album::factory()->create();
        $child = Album::factory()->for($root->owner, 'owner')->create(['parent_id' => $root->id]);
        $grandchild = Album::factory()->for($root->owner, 'owner')->create(['parent_id' => $child->id]);
        $user = addCollaborator($root, CollaboratorRole::Editor);
        expect($grandchild->hasDirectAccess($user))->toBeTrue();

        $child->update(['parent_id' => null]);

        expect($child->fresh()->hasDirectAccess($user))->toBeFalse()
            ->and($grandchild->fresh()->hasDirectAccess($user))->toBeFalse();
    });

    it('gives access when an album moves into a shared parent', function () {
        $root = Album::factory()->create();
        $album = Album::factory()->for($root->owner, 'owner')->create();
        $user = addCollaborator($root, CollaboratorRole::Contributor);
        expect($album->collaboratorRole($user))->toBeNull();

        $album->update(['parent_id' => $root->id]);

        expect($album->fresh()->collaboratorRole($user))->toBe(CollaboratorRole::Contributor);
    });

    it('follows group membership in the same request', function () {
        $album = Album::factory()->create();
        $user = User::factory()->create();
        $group = addGroupCollaborator($album, CollaboratorRole::Editor);
        expect($album->collaboratorRole($user))->toBeNull();

        $group->users()->attach($user);
        expect($album->collaboratorRole($user))->toBe(CollaboratorRole::Editor);

        $group->users()->detach($user);
        expect($album->collaboratorRole($user))->toBeNull();
    });

    it('ends the role when the group is deleted', function () {
        $album = Album::factory()->create();
        $user = User::factory()->create();
        $group = addGroupCollaborator($album, CollaboratorRole::Editor, $user);
        expect($album->collaboratorRole($user))->toBe(CollaboratorRole::Editor);

        $group->delete();

        expect($album->collaboratorRole($user))->toBeNull()
            ->and(DB::table('album_group_collaborators')->count())->toBe(0);
    });

    it('sees a changed or removed role in the same request', function () {
        $album = Album::factory()->create();
        $user = addCollaborator($album, CollaboratorRole::Contributor);
        expect($album->collaboratorRole($user))->toBe(CollaboratorRole::Contributor);

        $album->collaborators()->updateExistingPivot($user->id, ['role' => CollaboratorRole::Editor->value]);
        expect($album->collaboratorRole($user))->toBe(CollaboratorRole::Editor);

        $album->collaborators()->detach($user->id);
        expect($album->collaboratorRole($user))->toBeNull();
    });

    it('keeps the number of queries flat across many albums and groups', function () {
        $user = userWithRole(Role::Uploader);
        $root = Album::factory()->create();
        Album::factory()->for($root->owner, 'owner')->create(['parent_id' => $root->id]);
        addCollaborator($root, CollaboratorRole::Editor, $user);
        addGroupCollaborator(Album::factory()->create(), CollaboratorRole::Contributor, $user);
        $this->actingAs($user);

        $queries = function () {
            RequestMemo::instance()->forget(AlbumCollaboration::MEMO);
            DB::flushQueryLog();
            DB::enableQueryLog();
            PhotosTable::manageableAlbums('upload');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $queries();
        $few = $queries();

        Album::factory()->count(20)->for($root->owner, 'owner')->create(['parent_id' => $root->id]);
        foreach (range(1, 10) as $ignored) {
            addGroupCollaborator(Album::factory()->create(), CollaboratorRole::Organizer, $user);
        }

        expect($queries())->toBe($few);
    });
});

describe('the creator of a sub-album', function () {
    it('lets the creator delete it with any current role', function (CollaboratorRole $role) {
        $album = Album::factory()->create();
        $creator = addCollaborator($album, CollaboratorRole::Organizer);
        $subAlbum = subAlbumCreatedBy($creator, $album);

        $album->collaborators()->updateExistingPivot($creator->id, ['role' => $role->value]);

        expect($creator->can('delete', $subAlbum))->toBeTrue()
            ->and($creator->can('restore', $subAlbum))->toBeTrue()
            ->and($creator->can('forceDelete', $subAlbum))->toBeTrue()
            ->and($creator->can('update', $subAlbum))->toBeFalse();
    })->with(CollaboratorRole::cases());

    it('does not let another collaborator delete it, or the creator delete the parent', function () {
        $album = Album::factory()->create();
        $creator = addCollaborator($album, CollaboratorRole::Organizer);
        $editor = addCollaborator($album, CollaboratorRole::Editor);
        $subAlbum = subAlbumCreatedBy($creator, $album);

        expect($editor->can('delete', $subAlbum))->toBeFalse()
            ->and($creator->can('delete', $album))->toBeFalse();
    });

    it('ends when the creator loses their role or the album moves away', function (Closure $change) {
        $album = Album::factory()->create();
        $creator = addCollaborator($album, CollaboratorRole::Organizer);
        $subAlbum = subAlbumCreatedBy($creator, $album);

        $change($album, $subAlbum, $creator);

        expect($creator->can('delete', $subAlbum->fresh()))->toBeFalse();
    })->with([
        'role removed' => [fn (Album $album, Album $subAlbum, User $creator) => $album->collaborators()->detach($creator->id)],
        'moved out' => [fn (Album $album, Album $subAlbum) => $subAlbum->update(['parent_id' => null])],
    ]);
});

describe('access', function () {
    it('opens the album whatever its visibility, password or end date', function (array $state) {
        $album = Album::factory()->create($state);
        $user = addCollaborator($album, CollaboratorRole::Contributor);
        $stranger = User::factory()->create();

        expect(AlbumAccess::canView($user, $album))->toBeTrue()
            ->and(AlbumAccess::requiresPassword($user, $album))->toBeFalse()
            ->and($album->isVisibleTo($user))->toBeTrue()
            ->and(Album::listableFor($user)->whereKey($album->id)->exists())->toBeTrue()
            ->and(AlbumAccess::canView($stranger, $album))->toBeFalse();
    })->with([
        'private' => [['visibility' => Visibility::Private]],
        'restricted' => [['visibility' => Visibility::Restricted]],
        'password' => [['visibility' => Visibility::Registered, 'password' => 'a secret', 'access_expires_at' => now()->subDay()]],
        'expired' => [['visibility' => Visibility::Public, 'access_expires_at' => now()->subDay()]],
    ]);

    it('keeps access when the visibility changes', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $user = addCollaborator($album, CollaboratorRole::Contributor);

        $album->update(['visibility' => Visibility::Private]);

        expect(AlbumAccess::canView($user, $album->fresh()))->toBeTrue();
    });

    it('does not let a role edit or trash someone else\'s photo', function () {
        $album = Album::factory()->create();
        $photo = galleryPhoto($album, $album->owner);
        $editor = addCollaborator($album, CollaboratorRole::Editor);

        expect($editor->can('update', $photo))->toBeFalse()
            ->and($editor->can('delete', $photo))->toBeFalse()
            ->and(AlbumAccess::canDownload($editor, $photo))->toBeFalse();
    });

    it('leaves collaboration out of the photos someone may reuse in the admin', function () {
        $album = Album::factory()->create();
        $others = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);
        $user = addCollaborator($album, CollaboratorRole::Editor, userWithRole(Role::Uploader));
        $own = Photo::factory()->inAlbum($album)->create(['uploader_id' => $user->id]);

        expect(Photo::visibleTo($user)->pluck('photos.id')->all())->toBe([$own->id])
            ->and($others->exists)->toBeTrue();
    });
});

describe('system and trashed albums', function () {
    it('refuses collaborators on system albums', function () {
        $owner = User::factory()->create();
        $inbox = Album::inbox($owner);
        $user = User::factory()->create();

        $inbox->collaborators()->attach($user, ['role' => CollaboratorRole::Editor->value]);
        $inbox->collaboratorGroups()->attach(Group::factory()->create(), ['role' => CollaboratorRole::Editor->value]);

        expect(DB::table('album_collaborators')->count())->toBe(0)
            ->and(DB::table('album_group_collaborators')->count())->toBe(0);
    });

    it('does not inherit a role into a system album', function () {
        $owner = User::factory()->create();
        $root = Album::factory()->for($owner, 'owner')->create();
        $inbox = Album::inbox($owner);
        $inbox->update(['parent_id' => $root->id]);
        $user = addCollaborator($root, CollaboratorRole::Editor);

        expect($inbox->fresh()->collaboratorRole($user))->toBeNull()
            ->and($inbox->fresh()->hasDirectAccess($user))->toBeFalse();
    });

    it('allows none of the contribution abilities on trashed albums', function (string $ability) {
        $album = Album::factory()->create();
        $user = addCollaborator($album, CollaboratorRole::Editor);

        $album->delete();

        expect($user->can($ability, $album))->toBeFalse()
            ->and($album->hasDirectAccess($user))->toBeFalse();
    })->with(['upload', 'removePhotos', 'createSubAlbum', 'editSettings', 'manageCollaborators', 'leave']);

    it('gives the role back when the album is restored', function () {
        $album = Album::factory()->create();
        $user = addCollaborator($album, CollaboratorRole::Editor);
        $album->delete();

        $album->restore();

        expect($album->fresh()->collaboratorRole($user))->toBe(CollaboratorRole::Editor);
    });
});

describe('the owner', function () {
    it('cannot be added as a direct collaborator', function () {
        $album = Album::factory()->create();

        $album->collaborators()->attach($album->owner_id, ['role' => CollaboratorRole::Contributor->value]);

        expect(DB::table('album_collaborators')->count())->toBe(0);
    });

    it('loses their direct row when they become the owner', function () {
        $album = Album::factory()->create();
        $user = addCollaborator($album, CollaboratorRole::Editor);

        $album->update(['owner_id' => $user->id]);

        expect(DB::table('album_collaborators')->where('album_id', $album->id)->count())->toBe(0);
    });

    it('keeps owner rights when a group gives them a lower role', function () {
        $album = Album::factory()->create();
        addGroupCollaborator($album, CollaboratorRole::Contributor, $album->owner);

        expect($album->collaboratorRole($album->owner))->toBeNull()
            ->and($album->owner->can('update', $album))->toBeTrue();
    });
});

describe('albums shared with someone', function () {
    it('checks any number of albums with two queries per person', function () {
        $user = userWithRole(Role::User);
        $group = Group::factory()->create();
        $group->users()->attach($user);
        $albums = Album::factory()->count(3)->visibility(Visibility::Restricted)->create();
        $albums[0]->sharedUsers()->attach($user);
        $albums[1]->sharedGroups()->attach($group);
        DB::enableQueryLog();

        $shared = $albums->map(fn (Album $album) => $album->isSharedWith($user))->all();

        expect($shared)->toBe([true, true, false]);
        expect(DB::getQueryLog())->toHaveCount(2);
    });

    it('keeps the shares of different people apart', function () {
        [$invited, $other] = [userWithRole(Role::User), userWithRole(Role::User)];
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedUsers()->attach($invited);
        $album->isSharedWith($invited);

        $sharedWithOther = $album->isSharedWith($other);

        expect($sharedWithOther)->toBeFalse();
    });

    it('stops sharing at once when the share is removed during the request', function () {
        $user = userWithRole(Role::User);
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedUsers()->attach($user);
        $album->isSharedWith($user);

        $album->sharedUsers()->detach($user);

        expect($album->isSharedWith($user))->toBeFalse();
    });

    it('shares at once when someone joins a sharing group during the request', function () {
        $user = userWithRole(Role::User);
        $group = Group::factory()->create();
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedGroups()->attach($group);
        $album->isSharedWith($user);

        $group->users()->attach($user);

        expect($album->isSharedWith($user))->toBeTrue();
    });
});

describe('photos and albums beyond a single role', function () {
    it('moves sub-albums along when their parent is renamed or moved', function () {
        $a = Album::factory()->create(['title' => 'A']);
        $b = Album::factory()->create(['title' => 'B']);
        $child = Album::factory()->create(['title' => 'Child', 'parent_id' => $a->id]);

        $a->update(['parent_id' => $b->id]);

        expect($child->fresh()->tree_path)->toStartWith($a->fresh()->tree_path)
            ->and($a->fresh()->tree_path)->toStartWith($b->fresh()->tree_path)
            ->and($child->fresh()->depth())->toBe(2);
    });

    it('lets people upload only into albums they may edit, not into albums shared with them', function () {
        $uploader = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $own = Album::factory()->for($uploader, 'owner')->create();
        $shared = Album::factory()->visibility(Visibility::Restricted)->create();
        $shared->sharedUsers()->attach($uploader);

        expect($uploader->can('upload', $own))->toBeTrue()
            ->and($uploader->can('view', $shared))->toBeTrue()
            ->and($uploader->can('upload', $shared))->toBeFalse();

        $member = tap(User::factory()->create())->assignRole(Role::User->value);
        expect($member->can('upload', Album::factory()->for($member, 'owner')->create()))->toBeFalse();
    });

    it('counts a photo as visible to someone through any album they may list, even one in the trash', function () {
        $user = User::factory()->create();
        $group = Group::factory()->create();
        $group->users()->attach($user);
        $sharedWithGroup = Album::factory()->visibility(Visibility::Restricted)->create();
        $sharedWithGroup->sharedGroups()->attach($group);
        $trashedPublic = Album::factory()->visibility(Visibility::Public)->create();
        $photos = [
            'public' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Public)->create())->create(),
            'members' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Registered)->create())->create(),
            'group share' => Photo::factory()->inAlbum($sharedWithGroup)->create(),
            'own private album' => Photo::factory()->inAlbum(Album::factory()->create(['owner_id' => $user->id]))->create(),
            'own upload elsewhere' => Photo::factory()->inAlbum(Album::factory()->create())->create(['uploader_id' => $user->id]),
            'public album in the trash' => Photo::factory()->inAlbum($trashedPublic)->create(),
            'not shared' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Restricted)->create())->create(),
            'unlisted' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Unlisted)->create())->create(),
            'expired' => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Public)->create(['access_expires_at' => now()->subDay()]))->create(),
            'private' => Photo::factory()->inAlbum(Album::factory()->create())->create(),
        ];
        $trashedPublic->delete();

        $visible = Photo::query()->visibleTo($user)->pluck('id')->all();

        $expected = ['public', 'members', 'group share', 'own private album', 'own upload elsewhere', 'public album in the trash'];
        expect(collect($photos)->filter(fn (Photo $photo) => in_array($photo->id, $visible, true))->keys()->all())->toBe($expected);
    });

    it('lets only people who manage all of a photo\'s albums move it to the trash', function () {
        $helper = uploadOnlyStaff();
        $mine = Album::factory()->for($helper, 'owner')->create();
        $photo = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg', width: 300, height: 200);
        $mine->addPhotos($photo);

        expect($helper->can('update', $photo))->toBeTrue()
            ->and($helper->can('delete', $photo))->toBeFalse();
    });

    it('matches who may edit a photo', function (Closure $person, bool $reusable) {
        $owner = User::factory()->create();
        $album = Album::factory()->create(['owner_id' => $owner->id, 'visibility' => Visibility::Public]);
        $photo = galleryPhoto($album, User::factory()->create());
        $user = $person($album, $photo, $owner);

        expect(Photo::reusableBy($user)->whereKey($photo->id)->exists())->toBe($reusable)
            ->and($user->can('update', $photo))->toBe($reusable);
    })->with([
        'a visitor of the public album' => [fn () => User::factory()->create(), false],
        'its uploader' => [fn ($album, $photo) => $photo->uploader, true],
        'the album owner' => [fn ($album, $photo, $owner) => $owner, true],
        'staff who may edit any album' => [fn () => tap(User::factory()->create())->givePermissionTo(Permission::EditAnyAlbum->value), true],
    ]);

    it('leaves photos the person cannot edit out when adding to their own album', function () {
        $viewer = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $public = Album::factory()->create(['owner_id' => User::factory()->create()->id, 'visibility' => Visibility::Public]);
        $foreign = galleryPhoto($public, $public->owner);
        $own = Album::factory()->create(['owner_id' => $viewer->id]);
        $mine = galleryPhoto($own, $viewer);

        expect(AlbumCollaboration::addablePhotoIds($own, $viewer, [$foreign->id, $mine->id]))->toBe([$mine->id]);
    });
});
