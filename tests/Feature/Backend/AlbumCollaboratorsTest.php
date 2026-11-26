<?php

use App\Enums\ActivityType;
use App\Enums\CollaboratorRole;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Forms\Components\PeopleAndGroupsSelect;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\User;
use App\Notifications\AlbumCollaboratorAdded;
use App\Notifications\AlbumShared;
use App\Support\PeopleAndGroups;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

describe('the Collaborators card for an owner', function () {
    beforeEach(function () {
        $this->owner = userWithRole(Role::Uploader);
        $this->album = Album::factory()->for($this->owner, 'owner')->create();
        $this->actingAs($this->owner);
    });

    it('is on the create page too, shows what Editors may do, and stays hidden for system albums', function () {
        $card = 'People and groups who work on this album with you.';

        expect(CollaboratorRole::Editor->getDescription())->toContain('publish the album')->toContain('original files');
        $this->get(AlbumResource::getUrl('create'))->assertOk()->assertSee($card);
        $this->get(AlbumResource::getUrl('edit', ['record' => $this->album]))
            ->assertOk()
            ->assertSee($card)
            ->assertSee('Editors can publish the album and, by turning on downloads, reach the original files.');
        $this->get(AlbumResource::getUrl('edit', ['record' => Album::inbox($this->owner)]))
            ->assertOk()
            ->assertDontSee($card);
    });

    it('adds a person with a role and tells them, but not the owner', function () {
        Notification::fake();
        $person = User::factory()->create();

        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->callAction('addCollaborators', ['invitees' => ['user:'.$person->id], 'role' => CollaboratorRole::Organizer->value])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('album_collaborators', ['album_id' => $this->album->id, 'user_id' => $person->id, 'role' => 'organizer', 'added_by' => $this->owner->id]);
        Notification::assertSentTo($person, AlbumCollaboratorAdded::class);
        Notification::assertNotSentTo($this->owner, AlbumCollaboratorAdded::class);
    });

    it('adds people and groups in one step and tells members, except those who already had that access', function () {
        Notification::fake();
        $person = User::factory()->create();
        $new = User::factory()->create();
        $alreadyEditor = addCollaborator($this->album, CollaboratorRole::Editor);
        $group = Group::factory()->create();
        $group->users()->attach([$new->id, $alreadyEditor->id, $this->owner->id]);

        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->callAction('addCollaborators', ['invitees' => ['user:'.$person->id, 'group:'.$group->id], 'role' => CollaboratorRole::Contributor->value]);

        $this->assertDatabaseHas('album_collaborators', ['album_id' => $this->album->id, 'user_id' => $person->id, 'role' => 'contributor']);
        $this->assertDatabaseHas('album_group_collaborators', ['album_id' => $this->album->id, 'group_id' => $group->id, 'role' => 'contributor']);
        Notification::assertSentTo([$person, $new], AlbumCollaboratorAdded::class);
        Notification::assertNotSentTo([$alreadyEditor, $this->owner], AlbumCollaboratorAdded::class);
    });

    it('logs a role change and tells the person when it goes up, keeping unsaved form edits', function () {
        Notification::fake();
        $this->album->update(['title' => 'Saved']);
        $person = addCollaborator($this->album, CollaboratorRole::Contributor, User::factory()->create(['name' => 'Anna']));
        ActivityEvent::query()->delete();

        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->fillForm(['title' => 'Still typing'])
            ->call('changeCollaboratorRole', 'user:'.$person->id, CollaboratorRole::Editor->value)
            ->assertNotified('Collaborators updated')
            ->assertSchemaStateSet(['title' => 'Still typing']);

        expect($this->album->collaboratorRole($person))->toBe(CollaboratorRole::Editor)
            ->and($this->album->fresh()->title)->toBe('Saved')
            ->and(ActivityEvent::where('type', ActivityType::Updated)->sole()->details())->toBe(['Anna: Contributor → Editor']);
        Notification::assertSentTo($person, AlbumCollaboratorAdded::class);
    });

    it('removes a person or a group', function (string $type) {
        $key = $type === 'user'
            ? 'user:'.addCollaborator($this->album, CollaboratorRole::Contributor)->id
            : 'group:'.addGroupCollaborator($this->album, CollaboratorRole::Contributor)->id;

        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->callAction(TestAction::make('removeCollaborator')->arguments(['key' => $key]));

        expect(DB::table('album_collaborators')->count() + DB::table('album_group_collaborators')->count())->toBe(0);
    })->with(['user', 'group']);

    it('rejects an unknown role', function () {
        $person = addCollaborator($this->album, CollaboratorRole::Contributor);

        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->call('changeCollaboratorRole', 'user:'.$person->id, 'owner')
            ->assertStatus(422);

        expect($this->album->collaboratorRole($person))->toBe(CollaboratorRole::Contributor);
    });

    it('ignores keys that do not exist or are not people or groups', function () {
        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->callAction('addCollaborators', ['invitees' => ['user:999999', 'album:'.$this->album->id, 'user:'.$this->owner->id], 'role' => CollaboratorRole::Editor->value]);

        expect(DB::table('album_collaborators')->count())->toBe(0);
    });

    it('lists people and groups together, by page and by search', function () {
        foreach (range(1, 9) as $number) {
            addCollaborator($this->album, CollaboratorRole::Contributor, User::factory()->create(['name' => 'Person '.$number]));
        }
        addGroupCollaborator($this->album, CollaboratorRole::Editor)->update(['name' => 'Zebra crew']);

        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->assertSee('Person 1')
            ->assertDontSee('Zebra crew')
            ->set('collaboratorPage', 2)
            ->assertSee('Zebra crew')
            ->set('collaboratorSearch', 'Person 3')
            ->assertSet('collaboratorPage', 1)
            ->assertSee('Person 3')
            ->assertDontSee('Person 4');
    });
});

describe('the Collaborators card for an owner without the permission', function () {
    it('shows the list but no way to change it', function () {
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
});

describe('the combined sharing picker', function () {
    beforeEach(function () {
        $this->admin = userWithRole(Role::Admin);
        $this->actingAs($this->admin);
        $this->album = Album::factory()->visibility(Visibility::Restricted)->create();
    });

    it('finds people and groups in one search, without e-mail addresses', function () {
        $person = User::factory()->create(['name' => 'Camp Leader', 'email' => 'leader@example.test']);
        $group = Group::factory()->create(['name' => 'Camp crew']);

        $select = Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->instance()->form->getComponent(fn ($component) => $component instanceof PeopleAndGroupsSelect && $component->getName() === 'shared_with');

        expect($select->getSearchResults('camp'))->toBe(['user:'.$person->id => 'Camp Leader', 'group:'.$group->id => 'Camp crew (group)'])
            ->and($select->getOptions())->toBe(['group:'.$group->id => 'Camp crew (group)'])
            ->and(PeopleAndGroups::search('%%'))->toBe([]); // wildcards are taken literally
    });

    it('saves people and groups from one field and removes what was taken out', function () {
        $removed = User::factory()->create();
        $this->album->sharedUsers()->attach($removed);
        $person = User::factory()->create();
        $group = Group::factory()->create();

        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->assertSchemaStateSet(['shared_with' => ['user:'.$removed->id]])
            ->fillForm(['shared_with' => ['user:'.$person->id, 'group:'.$group->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->album->sharedUsers()->pluck('users.id')->all())->toBe([$person->id])
            ->and($this->album->sharedGroups()->pluck('groups.id')->all())->toBe([$group->id]);
    });

    it('keeps the shares of an album that is not restricted', function () {
        $person = User::factory()->create();
        $this->album->sharedUsers()->attach($person);

        Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])
            ->fillForm(['visibility' => Visibility::Public->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->album->sharedUsers()->pluck('users.id')->all())->toBe([$person->id]);
    });

    it('shares a new album from the one field', function () {
        $group = Group::factory()->create();

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'New', 'owner_id' => $this->admin->id, 'visibility' => Visibility::Restricted->value, 'shared_with' => ['group:'.$group->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Album::where('title', 'New')->sole()->sharedGroups()->pluck('groups.id')->all())->toBe([$group->id]);
    });
});

describe('sharing as an Editor', function () {
    it('keeps the directory closed without the invite permission', function () {
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $shared = User::factory()->create(['name' => 'Anna Shared']);
        $album->sharedUsers()->attach($shared);
        User::factory()->create(['name' => 'Anna Stranger']);
        Group::factory()->create(['name' => 'Anna fans']);
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        $select = albumPage($album)->instance()->form
            ->getComponent(fn ($component) => $component instanceof PeopleAndGroupsSelect);

        expect($select->getSearchResults('anna'))->toBe(['user:'.$shared->id => 'Anna Shared'])
            ->and($select->getOptions())->toBe(['user:'.$shared->id => 'Anna Shared']);
    });

    it('lets an Editor without the permission remove shares but ignores new ones', function () {
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $kept = User::factory()->create();
        $removed = User::factory()->create();
        $new = User::factory()->create();
        $album->sharedUsers()->attach([$kept->id, $removed->id]);
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        albumPage($album)
            ->fillForm(['shared_with' => ['user:'.$kept->id, 'user:'.$new->id]])
            ->call('save');

        expect($album->sharedUsers()->pluck('users.id')->all())->toBe([$kept->id]);
    });

    it('lets an Editor with the permission share, and tells the new person', function () {
        Notification::fake();
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $editor = userWithRole(Role::Uploader);
        addCollaborator($album, CollaboratorRole::Editor, $editor);
        $person = User::factory()->create();
        $this->actingAs($editor);

        albumPage($album)
            ->fillForm(['shared_with' => ['user:'.$person->id], 'notify_shared' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->sharedUsers()->pluck('users.id')->all())->toBe([$person->id]);
        Notification::assertSentTo($person, AlbumShared::class);
    });
});
