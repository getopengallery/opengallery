<?php

use App\Enums\ActivityType;
use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Forms\Components\PeopleAndGroupsSelect;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Photos\Pages\EditPhoto;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Photos\Tables\PhotosTable;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AlbumCollaboratorAdded;
use App\Support\AccountDeletion;
use App\Support\AlbumAccess;
use App\Support\AlbumCollaboration;
use App\Support\AlbumDeletion;
use App\Support\PeopleAndGroups;
use App\Support\RequestMemo;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

    it('serves previews but not originals through a role until downloads are on', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->visibility(Visibility::Registered)->create();
        $media = galleryPhoto($album, $album->owner)->original();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        $this->get(route('media.show', [$media, 'thumb']))->assertOk();
        $this->get(route('media.show', $media))->assertNotFound();
        $this->get(route('albums.download', $album))->assertNotFound();

        $album->update(['allow_downloads' => true]);

        $this->get(route('media.show', $media))->assertOk();
    });

    it('serves no trashed photo through a role', function () {
        $album = Album::factory()->create();
        $photo = galleryPhoto($album, $album->owner);
        $media = $photo->original();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        $photo->delete();

        $this->get(route('media.show', [$media, 'thumb']))->assertNotFound();
    });

    it('does not let a role edit or trash someone else\'s photo', function () {
        $album = Album::factory()->create();
        $photo = galleryPhoto($album, $album->owner);
        $editor = addCollaborator($album, CollaboratorRole::Editor);

        expect($editor->can('update', $photo))->toBeFalse()
            ->and($editor->can('delete', $photo))->toBeFalse()
            ->and(AlbumAccess::canDownload($editor, $photo))->toBeFalse();
    });

    it('keeps the admin edit page closed to people without a role', function () {
        $album = Album::factory()->create();
        $this->actingAs(userWithRole(Role::Uploader));

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))->assertNotFound();
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

describe('removing photos and deleting albums', function () {
    it('moves a photo in no other album to its uploader\'s unsorted uploads', function () {
        $album = Album::factory()->create();
        $uploader = addCollaborator($album, CollaboratorRole::Contributor);
        $photo = Photo::factory()->inAlbum($album)->create(['uploader_id' => $uploader->id]);

        $result = $album->removePhotos(Photo::whereKey($photo->id)->get(), moveLastCopies: true);

        expect($result)->toBe(['removed' => 1, 'kept' => 0])
            ->and($photo->fresh()->trashed())->toBeFalse()
            ->and($photo->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($uploader)->id]);
    });

    it('falls back to the owner\'s unsorted uploads when the uploader is gone', function () {
        $album = Album::factory()->create();
        $photo = Photo::factory()->inAlbum($album)->create(['uploader_id' => null]);

        $album->removePhotos(Photo::whereKey($photo->id)->get(), moveLastCopies: true);

        expect($photo->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($album->owner)->id]);
    });

    it('still keeps the last copy in place for the admin\'s remove action', function () {
        $album = Album::factory()->create();
        $photo = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);

        expect($album->removePhotos(Photo::whereKey($photo->id)->get()))->toBe(['removed' => 0, 'kept' => 1]);
    });

    it('keeps collaborators\' uploads when the owner deletes their account with all data', function () {
        $album = Album::factory()->create();
        $collaborator = addCollaborator($album, CollaboratorRole::Contributor);
        $theirs = galleryPhoto($album, $collaborator);
        $ownersPhoto = galleryPhoto($album, $album->owner);

        AccountDeletion::deleteWithAllData($album->owner);

        expect($theirs->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($collaborator)->id])
            ->and(Photo::withTrashed()->find($ownersPhoto->id))->toBeNull();
    });

    it('keeps collaborators\' uploads when an album is deleted with its photos', function () {
        $album = Album::factory()->create();
        $child = Album::factory()->for($album->owner, 'owner')->create(['parent_id' => $album->id]);
        $collaborator = addCollaborator($album, CollaboratorRole::Contributor);
        $inChild = galleryPhoto($child, $collaborator);
        $album->delete();

        AlbumDeletion::forceDelete($child->fresh(), true, $album->owner);

        expect($inChild->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($collaborator)->id]);
    });

    it('keeps the album tree when a collaborator deletes their account', function () {
        $album = Album::factory()->create();
        $collaborator = addCollaborator($album, CollaboratorRole::Organizer);
        $subAlbum = Album::factory()->for($album->owner, 'owner')->create(['parent_id' => $album->id]);
        $upload = galleryPhoto($subAlbum, $collaborator);

        AccountDeletion::deleteWithAllData($collaborator);

        expect($subAlbum->fresh())->not->toBeNull()
            ->and($subAlbum->fresh()->owner_id)->toBe($album->owner_id)
            ->and($upload->fresh()->albums->pluck('id')->all())->toBe([$subAlbum->id])
            ->and($upload->fresh()->uploader_id)->toBeNull()
            ->and(DB::table('album_collaborators')->count())->toBe(0);
    });
});

describe('adding existing photos', function () {
    it('adds only own uploads from the media library to an album reached through a role', function () {
        $user = addCollaborator($target = Album::factory()->create(), CollaboratorRole::Editor, userWithRole(Role::Uploader));
        $own = Album::factory()->for($user, 'owner')->create();
        $mine = Photo::factory()->inAlbum($own)->create(['uploader_id' => $user->id]);
        $notMine = Photo::factory()->inAlbum($own)->create(['uploader_id' => User::factory()]);
        $this->actingAs($user);

        Livewire::test(ListPhotos::class)
            ->selectTableRecords([$mine->id, $notMine->id])
            ->callAction(TestAction::make('addToAlbum')->table()->bulk(), ['album_id' => $target->id]);

        expect($target->photos()->pluck('photos.id')->all())->toBe([$mine->id]);
    });

    it('adds only own uploads through the picker path', function () {
        $user = addCollaborator($target = Album::factory()->create(), CollaboratorRole::Editor, userWithRole(Role::Uploader));
        $own = Album::factory()->for($user, 'owner')->create();
        $mine = Photo::factory()->inAlbum($own)->create(['uploader_id' => $user->id]);
        $notMine = Photo::factory()->inAlbum($own)->create(['uploader_id' => User::factory()]);
        $this->actingAs($user);

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $target, 'pageClass' => EditAlbum::class])
            ->call('addPickedPhotos', [$mine->id, $notMine->id]);

        expect($target->photos()->pluck('photos.id')->all())->toBe([$mine->id]);
    });

    it('refuses someone else\'s photo in the photo form', function () {
        $user = addCollaborator($target = Album::factory()->create(), CollaboratorRole::Editor, userWithRole(Role::Uploader));
        $own = Album::factory()->for($user, 'owner')->create();
        $notMine = Photo::factory()->inAlbum($own)->create(['uploader_id' => User::factory()]);
        $this->actingAs($user);

        Livewire::test(EditPhoto::class, ['record' => $notMine->getRouteKey()])
            ->fillForm(['albums' => [$own->id, $target->id]])
            ->call('save')
            ->assertHasFormErrors(['albums']);

        expect($target->photos()->count())->toBe(0);
    });
});

describe('the Collaborators card', function () {
    it('lets the owner add a person with a role and tells them', function () {
        Notification::fake();
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        $person = User::factory()->create();
        $this->actingAs($owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->callAction('addCollaborators', ['invitees' => ['user:'.$person->id], 'role' => CollaboratorRole::Organizer->value])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('album_collaborators', ['album_id' => $album->id, 'user_id' => $person->id, 'role' => 'organizer', 'added_by' => $owner->id]);
        Notification::assertSentTo($person, AlbumCollaboratorAdded::class);
        Notification::assertNotSentTo($owner, AlbumCollaboratorAdded::class);
    });

    it('adds people and groups in one step and tells members, except those who already had that access', function () {
        Notification::fake();
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        $person = User::factory()->create();
        $new = User::factory()->create();
        $alreadyEditor = addCollaborator($album, CollaboratorRole::Editor);
        $group = Group::factory()->create();
        $group->users()->attach([$new->id, $alreadyEditor->id, $owner->id]);
        $this->actingAs($owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->callAction('addCollaborators', ['invitees' => ['user:'.$person->id, 'group:'.$group->id], 'role' => CollaboratorRole::Contributor->value]);

        $this->assertDatabaseHas('album_collaborators', ['album_id' => $album->id, 'user_id' => $person->id, 'role' => 'contributor']);
        $this->assertDatabaseHas('album_group_collaborators', ['album_id' => $album->id, 'group_id' => $group->id, 'role' => 'contributor']);
        Notification::assertSentTo([$person, $new], AlbumCollaboratorAdded::class);
        Notification::assertNotSentTo([$alreadyEditor, $owner], AlbumCollaboratorAdded::class);
    });

    it('logs a role change and tells the person when it goes up', function () {
        Notification::fake();
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        $person = addCollaborator($album, CollaboratorRole::Contributor, User::factory()->create(['name' => 'Anna']));
        ActivityEvent::query()->delete();
        $this->actingAs($owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->call('changeCollaboratorRole', 'user:'.$person->id, CollaboratorRole::Editor->value)
            ->assertNotified('Collaborators updated');

        expect($album->collaboratorRole($person))->toBe(CollaboratorRole::Editor)
            ->and(ActivityEvent::where('type', ActivityType::Updated)->sole()->details())->toBe(['Anna: Contributor → Editor']);
        Notification::assertSentTo($person, AlbumCollaboratorAdded::class);
    });

    it('removes a person or a group', function (string $type) {
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        $key = $type === 'user'
            ? 'user:'.addCollaborator($album, CollaboratorRole::Contributor)->id
            : 'group:'.addGroupCollaborator($album, CollaboratorRole::Contributor)->id;
        $this->actingAs($owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->callAction(TestAction::make('removeCollaborator')->arguments(['key' => $key]));

        expect(DB::table('album_collaborators')->count() + DB::table('album_group_collaborators')->count())->toBe(0);
    })->with(['user', 'group']);

    it('keeps unsaved form edits when a collaborator is changed', function () {
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create(['title' => 'Saved']);
        $person = addCollaborator($album, CollaboratorRole::Contributor);
        $this->actingAs($owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['title' => 'Still typing'])
            ->call('changeCollaboratorRole', 'user:'.$person->id, CollaboratorRole::Editor->value)
            ->assertSchemaStateSet(['title' => 'Still typing']);

        expect($album->fresh()->title)->toBe('Saved');
    });

    it('rejects an unknown role', function () {
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        $person = addCollaborator($album, CollaboratorRole::Contributor);
        $this->actingAs($owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->call('changeCollaboratorRole', 'user:'.$person->id, 'owner')
            ->assertStatus(422);

        expect($album->collaboratorRole($person))->toBe(CollaboratorRole::Contributor);
    });

    it('ignores keys that do not exist or are not people or groups', function () {
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        $this->actingAs($owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->callAction('addCollaborators', ['invitees' => ['user:999999', 'album:'.$album->id, 'user:'.$owner->id], 'role' => CollaboratorRole::Editor->value]);

        expect(DB::table('album_collaborators')->count())->toBe(0);
    });

    it('lists people and groups together, by page and by search', function () {
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        foreach (range(1, 9) as $number) {
            addCollaborator($album, CollaboratorRole::Contributor, User::factory()->create(['name' => 'Person '.$number]));
        }
        $group = addGroupCollaborator($album, CollaboratorRole::Editor);
        $group->update(['name' => 'Zebra crew']);
        $this->actingAs($owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertSee('Person 1')
            ->assertDontSee('Zebra crew')
            ->set('collaboratorPage', 2)
            ->assertSee('Zebra crew')
            ->set('collaboratorSearch', 'Person 3')
            ->assertSet('collaboratorPage', 1)
            ->assertSee('Person 3')
            ->assertDontSee('Person 4');
    });

    it('states on the card that Editors can publish and reach originals', function () {
        $owner = userWithRole(Role::Uploader);
        $album = Album::factory()->for($owner, 'owner')->create();
        $this->actingAs($owner);

        expect(CollaboratorRole::Editor->getDescription())->toContain('publish the album')->toContain('original files');
        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))
            ->assertOk()
            ->assertSee('Editors can publish the album and, by turning on downloads, reach the original files.');
    });

    it('shows an owner without the permission the list but no way to change it', function () {
        $album = Album::factory()->create();
        $album->owner->assignRole(Role::User->value);
        $person = addCollaborator($album, CollaboratorRole::Contributor, User::factory()->create(['name' => 'Zora Listed']));
        $this->actingAs($album->owner);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertSee('Zora Listed')
            ->assertActionHidden('addCollaborators')
            ->assertActionHidden(TestAction::make('removeCollaborator')->arguments(['key' => 'user:'.$person->id]))
            ->call('changeCollaboratorRole', 'user:'.$person->id, CollaboratorRole::Editor->value)
            ->assertForbidden();

        expect($album->collaboratorRole($person))->toBe(CollaboratorRole::Contributor);
    });

    it('is hidden for system albums', function () {
        $owner = userWithRole(Role::Uploader);
        $inbox = Album::inbox($owner);
        $this->actingAs($owner);

        $this->get(AlbumResource::getUrl('edit', ['record' => $inbox]))
            ->assertOk()
            ->assertDontSee('People and groups who work on this album with you.');
    });

    it('is on the create page too, for people who may invite', function () {
        $this->actingAs(userWithRole(Role::Uploader));

        $this->get(AlbumResource::getUrl('create'))
            ->assertOk()
            ->assertSee('People and groups who work on this album with you.');
    });
});

describe('the combined sharing picker', function () {
    it('finds people and groups in one search, without e-mail addresses', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
        $person = User::factory()->create(['name' => 'Camp Leader', 'email' => 'leader@example.test']);
        $group = Group::factory()->create(['name' => 'Camp crew']);
        $album = Album::factory()->visibility(Visibility::Restricted)->create();

        $select = Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->instance()->form->getComponent(fn ($component) => $component instanceof PeopleAndGroupsSelect && $component->getName() === 'shared_with');

        expect($select->getSearchResults('camp'))->toBe(['user:'.$person->id => 'Camp Leader', 'group:'.$group->id => 'Camp crew (group)'])
            ->and($select->getOptions())->toBe(['group:'.$group->id => 'Camp crew (group)']);
    });

    it('takes wildcards in a search literally', function () {
        User::factory()->create(['name' => 'Anna Example']);

        expect(PeopleAndGroups::search('%%'))->toBe([]);
    });

    it('saves people and groups from one field and removes what was taken out', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $removed = User::factory()->create();
        $album->sharedUsers()->attach($removed);
        $person = User::factory()->create();
        $group = Group::factory()->create();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertSchemaStateSet(['shared_with' => ['user:'.$removed->id]])
            ->fillForm(['shared_with' => ['user:'.$person->id, 'group:'.$group->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->sharedUsers()->pluck('users.id')->all())->toBe([$person->id])
            ->and($album->sharedGroups()->pluck('groups.id')->all())->toBe([$group->id]);
    });

    it('keeps the shares of an album that is not restricted', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $person = User::factory()->create();
        $album->sharedUsers()->attach($person);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['visibility' => Visibility::Public->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->sharedUsers()->pluck('users.id')->all())->toBe([$person->id]);
    });

    it('shares a new album from the one field', function () {
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
        $this->actingAs($admin);
        $group = Group::factory()->create();

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'New', 'owner_id' => $admin->id, 'visibility' => Visibility::Restricted->value, 'shared_with' => ['group:'.$group->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Album::where('title', 'New')->sole()->sharedGroups()->pluck('groups.id')->all())->toBe([$group->id]);
    });
});
