<?php

use App\Enums\ActivityType;
use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\PhotoOrder;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Actions\ActionIcons;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\Pages\ListTrashedAlbums;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Livewire\MediaPicker;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\AlbumGuest;
use App\Models\Group;
use App\Models\Page;
use App\Models\Photo;
use App\Models\Role as RoleModel;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\AlbumCollaboratorAdded;
use App\Notifications\AlbumShared;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('gallery');
    Storage::fake('local');
});

describe('the albums list', function () {
    it('lists sub-albums under their parent, links the owner and the row, and shows when sharing ends', function () {
        actingAsRole(Role::SuperAdmin);
        $camp = Album::factory()->create(['title' => 'Camp']);
        $day = Album::factory()->create(['title' => 'Day 3', 'parent_id' => $camp->id]);
        $autumn = Album::factory()->create(['title' => 'Autumn']);
        $zoo = Album::factory()->create(['title' => 'Zoo', 'access_expires_at' => now()->addYear()->setDate(2027, 1, 2)->setTime(18, 30)]);

        $list = Livewire::test(ListAlbums::class)
            ->assertCanSeeTableRecords([$autumn, $camp, $day, $zoo], inOrder: true)
            ->assertSeeHtml(UserResource::getUrl('edit', ['record' => $camp->owner]))
            ->assertSee('Shared until 02.01.2027 18:30')
            ->assertActionDoesNotExist(TestAction::make('edit')->table($camp));
        $table = $list->instance()->getTable();

        expect($day->fresh()->depth())->toBe(1)
            ->and($table->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
            ->and($table->hasDeferredFilters())->toBeFalse()
            ->and($table->getRecordUrl($camp))->toBe(AlbumResource::getUrl('edit', ['record' => $camp]))
            ->and($table->getColumns()['visibility']->isBadge())->toBeTrue();

        foreach (['title', 'owner.name', 'collaborator_role'] as $plain) {
            expect($table->getColumns()[$plain]->isBadge())->toBeFalse("{$plain} should be plain text");
        }
    });

    it('lists the albums someone works on and nobody else\'s', function () {
        $album = Album::factory()->create();
        $child = Album::factory()->for($album->owner, 'owner')->create(['parent_id' => $album->id]);
        $throughGroup = Album::factory()->create();
        $unrelated = Album::factory()->visibility(Visibility::Public)->create();
        $member = addCollaborator($album, CollaboratorRole::Contributor);
        addGroupCollaborator($throughGroup, CollaboratorRole::Organizer, $member);
        $this->actingAs($member);

        Livewire::test(ListAlbums::class)
            ->assertCanSeeTableRecords([$album, $child, $throughGroup])
            ->assertCanNotSeeTableRecords([$unrelated]);
    });

    it('offers no trash, and "Add album inside" from Organizer up', function (CollaboratorRole $role, bool $addsInside) {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator($album, $role));

        $list = Livewire::test(ListAlbums::class)
            ->assertActionHidden(TestAction::make('delete')->table($album));

        $addsInside
            ? $list->assertActionVisible(TestAction::make('addChild')->table($album))
            : $list->assertActionHidden(TestAction::make('addChild')->table($album));
    })->with([
        'contributor' => [CollaboratorRole::Contributor, false],
        'organizer' => [CollaboratorRole::Organizer, true],
        'editor' => [CollaboratorRole::Editor, true],
    ]);

    it('lists someone\'s "Unsorted uploads" among their own albums, not other people\'s', function () {
        [$anna, $ben] = [User::factory()->create(), User::factory()->create()];
        $annasInbox = Album::inbox($anna);
        $bensInbox = Album::inbox($ben);
        $this->actingAs($anna->assignRole(Role::Uploader->value));

        Livewire::test(ListAlbums::class)
            ->assertCanSeeTableRecords([$annasInbox])
            ->assertCanNotSeeTableRecords([$bensInbox]);
    });

    it('changes the order among albums at the same level by dragging, without moving albums between levels', function () {
        actingAsRole();
        [$a, $b, $c] = Album::factory()->count(3)->sequence(['title' => 'A'], ['title' => 'B'], ['title' => 'C'])->create();
        [$a1, $a2] = Album::factory()->count(2)->sequence(['title' => 'A1', 'parent_id' => $a->id], ['title' => 'A2', 'parent_id' => $a->id])->create();

        // Dropped order: C, A2 (dragged above A), A, A1, B.
        Livewire::test(ListAlbums::class)->call('reorderTable', [$c->id, $a2->id, $a->id, $a1->id, $b->id]);

        expect(Album::whereNull('parent_id')->orderBy('tree_path')->pluck('title')->all())->toBe(['C', 'A', 'B'])
            ->and($a->children()->get()->sortBy('tree_path')->pluck('title')->values()->all())->toBe(['A2', 'A1'])
            ->and($a2->fresh()->parent_id)->toBe($a->id);

        Livewire::test(ListAlbums::class)->assertCanSeeTableRecords([$c, $a, $a2, $a1, $b], inOrder: true);
    });

    it('lets only staff who may edit any album change the album order', function () {
        RoleModel::findOrCreate('editor')->syncPermissions([Permission::AccessAdmin->value, Permission::CreateAlbums->value]);
        $this->actingAs(tap(User::factory()->create())->assignRole('editor'));
        $album = Album::factory()->create(['sort_order' => 3]);

        Livewire::test(ListAlbums::class)->call('reorderTable', [$album->id])->assertForbidden();

        expect($album->fresh()->sort_order)->toBe(3);
    });
});

describe('who sees which albums in the admin', function () {
    it('shows only their own albums to people who may not see every album', function () {
        $creator = albumCreator();
        $this->actingAs($creator);
        $own = Album::factory()->for($creator, 'owner')->create();
        $othersPrivate = Album::factory()->visibility(Visibility::Private)->create();
        $othersPublic = Album::factory()->visibility(Visibility::Public)->create();

        Livewire::test(ListAlbums::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$othersPrivate, $othersPublic])
            ->assertTableFilterHidden('owner');

        $this->get(AlbumResource::getUrl('edit', ['record' => $othersPrivate]))->assertNotFound();
    });

    it('shows every album to people who may see, edit or delete any album', function (Permission $permission) {
        RoleModel::findOrCreate('album staff')->givePermissionTo([Permission::AccessAdmin->value, $permission->value]);
        $this->actingAs(tap(User::factory()->create())->assignRole('album staff'));
        $othersPrivate = Album::factory()->visibility(Visibility::Private)->create();

        Livewire::test(ListAlbums::class)
            ->assertCanSeeTableRecords([$othersPrivate])
            ->assertTableFilterVisible('owner');
    })->with([Permission::ViewAnyAlbum, Permission::EditAnyAlbum, Permission::DeleteAnyAlbum]);

    it('creates albums as the person themselves, without an owner field', function () {
        $creator = albumCreator();
        $this->actingAs($creator);
        $someoneElse = User::factory()->create();

        Livewire::test(CreateAlbum::class)
            ->assertFormFieldHidden('owner_id')
            ->set('data.title', 'Mine')
            ->set('data.owner_id', $someoneElse->id)
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Album::where('title', 'Mine')->sole()->owner_id)->toBe($creator->id);
    });

    it('lets people who may edit every album give an album to someone else', function () {
        actingAsRole();
        $album = Album::factory()->create();
        $heir = User::factory()->create();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertFormFieldVisible('owner_id')
            ->fillForm(['owner_id' => $heir->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->fresh()->owner_id)->toBe($heir->id);
    });

    it('offers only their own albums as parent', function () {
        $creator = albumCreator();
        $this->actingAs($creator);
        $own = Album::factory()->for($creator, 'owner')->create();
        $others = Album::factory()->create();

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Inside someone else', 'parent_id' => $others->id])
            ->call('create')
            ->assertHasFormErrors(['parent_id']);

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Inside my own', 'parent_id' => $own->id])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Album::where('title', 'Inside my own')->sole()->parent_id)->toBe($own->id);
    });

    it('keeps an album that staff placed inside someone else\'s album editable for its owner', function () {
        $creator = albumCreator();
        $this->actingAs($creator);
        $staffAlbum = Album::factory()->create();
        $mine = Album::factory()->for($creator, 'owner')->create(['parent_id' => $staffAlbum->id]);

        Livewire::test(EditAlbum::class, ['record' => $mine->getRouteKey()])
            ->fillForm(['title' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($mine->fresh())->title->toBe('Renamed')->parent_id->toBe($staffAlbum->id);
    });

    it('lets the built-in Uploader role create albums in the admin and see only their own', function () {
        $uploader = actingAsRole(Role::Uploader);
        $others = Album::factory()->visibility(Visibility::Registered)->create();

        $this->get(AlbumResource::getUrl())->assertOk();

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Summer camp'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(ListAlbums::class)
            ->assertCanSeeTableRecords([Album::where('title', 'Summer camp')->sole()])
            ->assertCanNotSeeTableRecords([$others]);

        expect(Album::where('title', 'Summer camp')->sole()->owner_id)->toBe($uploader->id);
    });
});

describe('the album page by role', function () {
    it('opens for every role with the role named, offering no trash and the "Add photos" button', function (CollaboratorRole $role) {
        $album = Album::factory()->create();
        Photo::factory()->inAlbum($album)->create();
        $this->actingAs(addCollaborator($album, $role));

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))
            ->assertOk()
            ->assertSee(__('Your role: :role', ['role' => $role->getLabel()]));

        albumPage($album)
            ->assertActionHidden('delete')
            ->assertActionHidden('restore')
            ->assertActionHidden('forceDelete');
        albumPhotosSection($album)
            ->assertActionVisible(TestAction::make('addFromLibrary')->table())
            ->assertActionHidden(TestAction::make('delete')->table()->bulk());
    })->with(CollaboratorRole::cases());

    it('stays closed to someone without a role on the album', function () {
        $album = Album::factory()->create();
        $withRoleElsewhere = addCollaborator(Album::factory()->create(), CollaboratorRole::Editor);

        $this->actingAs($withRoleElsewhere)->get(AlbumResource::getUrl('edit', ['record' => $album]))->assertNotFound();
        $this->actingAs(userWithRole(Role::Uploader))->get(AlbumResource::getUrl('edit', ['record' => $album]))->assertNotFound();
    });

    it('stays closed for trashed albums', function () {
        $album = Album::factory()->create();
        $member = addCollaborator($album, CollaboratorRole::Editor);
        addCollaborator(Album::factory()->create(), CollaboratorRole::Contributor, $member);
        $this->actingAs($member);
        $album->delete();

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))->assertNotFound();
    });

    it('is read-only below Editor and refuses a crafted save with 403', function (CollaboratorRole $role) {
        $album = Album::factory()->create(['title' => 'Kept']);
        $this->actingAs(addCollaborator($album, $role));

        albumPage($album)
            ->assertDontSee(__('Visibility & sharing'))
            ->set('data.title', 'Changed')
            ->call('save')
            ->assertForbidden();

        expect($album->fresh()->title)->toBe('Kept');
    })->with([CollaboratorRole::Contributor, CollaboratorRole::Organizer]);

    it('lets an Editor without admin access save the settings', function () {
        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
        $album = Album::factory()->create(['title' => 'Before']);
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        albumPage($album)
            ->fillForm([
                'title' => 'After',
                'description' => 'New words',
                'photo_order' => PhotoOrder::TitleAsc->value,
                'visibility' => Visibility::Public->value,
                'allow_downloads' => true,
                'access_expires_at' => now()->addWeek(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->fresh())
            ->title->toBe('After')
            ->description->toBe('New words')
            ->photo_order->toBe(PhotoOrder::TitleAsc)
            ->visibility->toBe(Visibility::Public)
            ->allow_downloads->toBeTrue()
            ->access_expires_at->not->toBeNull();
    });

    it('ignores crafted owner, parent and other blocked fields', function () {
        $parent = Album::factory()->create();
        $album = Album::factory()->visibility(Visibility::Unlisted)->create();
        $editor = addCollaborator($album, CollaboratorRole::Editor);
        $before = $album->fresh()->only(['owner_id', 'parent_id', 'slug', 'sort_order', 'tree_path', 'share_token', 'system_album']);
        $this->actingAs($editor);

        albumPage($album)
            ->set('data.owner_id', $editor->id)
            ->set('data.parent_id', $parent->id)
            ->set('data.slug', 'stolen')
            ->set('data.sort_order', 99)
            ->set('data.tree_path', 'x/')
            ->set('data.share_token', 'stolen')
            ->set('data.system_album', Album::INBOX)
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->fresh()->only(array_keys($before)))->toBe($before);
    });

    it('never sends the password hash to the browser, keeps it while the field stays empty and replaces it when changed', function () {
        $album = Album::factory()->create(['visibility' => Visibility::Registered, 'password' => 'old secret']);
        $hash = $album->fresh()->password;
        $fingerprint = $album->fresh()->passwordFingerprint();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))->assertOk()->assertDontSee($hash, false);

        albumPage($album)->fillForm(['title' => 'New title'])->call('save')->assertHasNoFormErrors();
        expect($album->fresh()->passwordFingerprint())->toBe($fingerprint);

        // Earlier visitors are locked out, as the fingerprint changes with the password.
        albumPage($album)->fillForm(['password' => 'new secret'])->call('save')->assertHasNoFormErrors();
        expect($album->fresh()->passwordFingerprint())->not->toBe($fingerprint)
            ->and(Hash::check('new secret', $album->fresh()->password))->toBeTrue();
    });

    it('does not let an Editor change who works on the album', function () {
        $album = Album::factory()->create();
        $editor = addCollaborator($album, CollaboratorRole::Editor);
        $other = addCollaborator($album, CollaboratorRole::Contributor);
        $this->actingAs($editor);

        albumPage($album)
            ->assertSee($other->name)
            ->assertActionHidden('addCollaborators')
            ->assertActionHidden(TestAction::make('removeCollaborator')->arguments(['key' => 'user:'.$other->id]))
            ->call('changeCollaboratorRole', 'user:'.$editor->id, CollaboratorRole::Editor->value)
            ->assertForbidden();

        expect($album->collaboratorRole($other))->toBe(CollaboratorRole::Contributor);
    });

    it('names a role that comes from a parent album in the list', function () {
        $parent = Album::factory()->create();
        $album = Album::factory()->for($parent->owner, 'owner')->create(['parent_id' => $parent->id]);
        $member = addCollaborator($parent, CollaboratorRole::Organizer, User::factory()->create(['name' => 'Zora Parent']));
        $this->actingAs($member);

        albumPage($album)->assertSee('Zora Parent')->assertSee(__('from a parent album'));
    });
});

describe('the album settings', function () {
    it('does not offer an album or its sub-albums as its own parent', function () {
        actingAsRole();
        $parent = Album::factory()->create(['title' => 'Parent']);
        $child = Album::factory()->create(['title' => 'Child', 'parent_id' => $parent->id]);

        Livewire::test(EditAlbum::class, ['record' => $parent->slug])
            ->set('data.parent_id', $child->id)
            ->call('save')
            ->assertHasErrors('data.parent_id');

        expect($parent->fresh()->parent_id)->toBeNull();
    });

    it('finds people to share with by name or e-mail instead of listing every user', function () {
        actingAsRole();
        $ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'countess@example.com']);
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        $album = Album::factory()->visibility(Visibility::Restricted)->create();

        $select = Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->instance()->form->getComponent(fn ($component) => $component instanceof Select && $component->getName() === 'shared_with');

        expect($select->getOptions())->toBe([])
            ->and($select->getSearchResults('countess'))->toBe(['user:'.$ada->id => 'Ada Lovelace'])
            ->and($select->getSearchResults('Lovelace'))->toBe(['user:'.$ada->id => 'Ada Lovelace']);
    });

    it('offers downloads only while the site allows them, and lets visitors download only while the album is not private', function () {
        actingAsRole();
        $album = Album::factory()->visibility(Visibility::Public)->create();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])->assertFormFieldHidden('allow_downloads');

        Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertFormFieldVisible('allow_downloads')
            ->fillForm(['allow_downloads' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        expect($album->fresh()->allow_downloads)->toBeTrue();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['visibility' => Visibility::Private])
            ->call('save')
            ->assertHasNoFormErrors();
        expect($album->fresh()->allow_downloads)->toBeFalse();
    });

    it('sets, keeps and removes the password from the edit page', function () {
        actingAsRole();
        $album = Album::factory()->visibility(Visibility::Public)->create();

        Livewire::test(EditAlbum::class, ['record' => $album->slug])
            ->set('data.protect', true)->set('data.password', 'open-sesame')->call('save')->assertHasNoErrors();
        $hash = $album->fresh()->password;
        expect(Hash::check('open-sesame', $hash))->toBeTrue();

        Livewire::test(EditAlbum::class, ['record' => $album->slug])->set('data.title', 'Renamed')->call('save');
        expect($album->fresh()->password)->toBe($hash);

        Livewire::test(EditAlbum::class, ['record' => $album->slug])->set('data.protect', false)->call('save');
        expect($album->fresh()->hasPassword())->toBeFalse();
    });

    it('uses the site\'s default photo order for new albums only', function () {
        actingAsRole();
        $existing = Album::factory()->create();

        applySiteSettings([SiteSettings::DEFAULT_PHOTO_ORDER => PhotoOrder::TakenDesc->value]);

        expect(Album::factory()->create()->photo_order)->toBe(PhotoOrder::TakenDesc)
            ->and($existing->fresh()->photo_order)->toBe(PhotoOrder::Manual);

        Livewire::test(CreateAlbum::class)->assertSchemaStateSet(['photo_order' => PhotoOrder::TakenDesc]);
    });
});

describe('the cover', function () {
    it('uses a photo from elsewhere as cover and adds it to the album', function () {
        actingAsRole();
        $album = Album::factory()->create();
        galleryPhoto($album, name: 'First.jpg', width: 160, height: 120);
        $elsewhere = galleryPhoto(Album::factory()->create(), name: 'Elsewhere.jpg', width: 160, height: 120);

        Livewire::test(EditAlbum::class, ['record' => $album->slug])
            ->set('data.cover_photo_id', $elsewhere->id)
            ->call('save')
            ->assertHasNoErrors();

        expect($album->fresh()->cover_photo_id)->toBe($elsewhere->id)
            ->and($album->photos()->whereKey($elsewhere->id)->exists())->toBeTrue();
    });

    it('does not accept a cover the user may not see', function () {
        RoleModel::findOrCreate('editor2')->syncPermissions([Permission::AccessAdmin->value]);
        $editor = tap(User::factory()->create())->assignRole('editor2');
        $album = Album::factory()->for($editor, 'owner')->create();
        $hidden = galleryPhoto(Album::factory()->create(), name: 'Hidden.jpg', width: 160, height: 120);
        $this->actingAs($editor);

        Livewire::test(EditAlbum::class, ['record' => $album->slug])
            ->set('data.cover_photo_id', $hidden->id)
            ->call('save');

        expect($album->fresh()->cover_photo_id)->toBeNull();
    });

    it('refuses a cover the person may only look at', function () {
        $owner = User::factory()->create();
        $viewer = userWithRole(Role::Uploader);
        $restricted = Album::factory()->create(['owner_id' => $owner->id, 'visibility' => Visibility::Restricted]);
        $restricted->sharedUsers()->attach($viewer);
        $photo = galleryPhoto($restricted, $owner);
        $own = Album::factory()->create(['owner_id' => $viewer->id, 'visibility' => Visibility::Public, 'allow_downloads' => true]);
        $this->actingAs($viewer);

        Livewire::test(EditAlbum::class, ['record' => $own->getRouteKey()])
            ->fillForm(['cover_photo_id' => $photo->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($own->fresh()->cover_photo_id)->not->toBe($photo->id)
            ->and($own->photos()->whereKey($photo->id)->exists())->toBeFalse();
    });

    it('accepts a photo already in the album for an Editor', function () {
        $album = Album::factory()->create();
        Photo::factory()->inAlbum($album)->create();
        $second = Photo::factory()->inAlbum($album)->create();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        albumPage($album->fresh())->fillForm(['cover_photo_id' => $second->id])->call('save')->assertHasNoFormErrors();

        expect($album->fresh()->cover_photo_id)->toBe($second->id);
    });

    it('accepts the Editor\'s own upload and adds it to the album', function () {
        $album = Album::factory()->create();
        $editor = addCollaborator($album, CollaboratorRole::Editor);
        $own = Photo::factory()->inAlbum(Album::inbox($editor))->create(['uploader_id' => $editor->id]);
        $this->actingAs($editor);

        albumPage($album)->fillForm(['cover_photo_id' => $own->id])->call('save')->assertHasNoFormErrors();

        expect($album->fresh()->cover_photo_id)->toBe($own->id)
            ->and($album->photos()->whereKey($own->id)->exists())->toBeTrue();
    });

    it('refuses someone else\'s photo the Editor can see elsewhere', function () {
        $album = Album::factory()->create();
        $public = Album::factory()->visibility(Visibility::Public)->create();
        $others = Photo::factory()->inAlbum($public)->create(['uploader_id' => $public->owner_id]);
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        albumPage($album)->fillForm(['cover_photo_id' => $others->id])->call('save');

        expect($album->fresh()->cover_photo_id)->toBeNull()
            ->and($album->photos()->count())->toBe(0);
    });

    it('offers "Use as cover" to Editors only', function () {
        $album = Album::factory()->create();
        $photo = Photo::factory()->inAlbum($album)->create();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Organizer));

        albumPhotosSection($album)->assertActionHidden(TestAction::make('useAsCover')->table()->bulk());

        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        albumPhotosSection($album)
            ->selectTableRecords([$photo->id])
            ->callAction(TestAction::make('useAsCover')->table()->bulk());

        expect($album->fresh()->cover_photo_id)->toBe($photo->id);
    });

    it('uses exactly one selected photo as the cover', function () {
        actingAsRole();
        $album = Album::factory()->create();
        [$first, $second] = [galleryPhoto($album), galleryPhoto($album)];

        $manager = albumPhotosSection($album);
        $manager->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('useAsCover')->table()->bulk())
            ->assertNotified('Select exactly one photo to use as the cover.');
        expect($album->fresh()->cover_photo_id)->toBe($first->id);

        $manager->selectTableRecords([$second->id])->callAction(TestAction::make('useAsCover')->table()->bulk());
        expect($album->fresh()->cover_photo_id)->toBe($second->id);
    });
});

describe('tags', function () {
    it('adds tags when an album is created and removes them when it is edited', function () {
        actingAsRole();
        Setting::put(SiteSettings::TAGS_PREDEFINED, ['Predefined one']);

        Livewire::test(CreateAlbum::class)
            ->assertSee('Predefined one')
            ->assertSee('Search or add a tag')
            ->fillForm(['title' => 'Holiday', 'tag_names' => ['Beach', 'Family']])
            ->call('create')
            ->assertHasNoFormErrors();

        $album = Album::where('title', 'Holiday')->sole();
        expect(Tagging::namesOf($album))->toBe(['Beach', 'Family']);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertSchemaStateSet(['tag_names' => ['Beach', 'Family']])
            ->fillForm(['tag_names' => ['Beach']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Tagging::namesOf($album))->toBe(['Beach']);
    });

    it('does not let a contributor change an album\'s tags', function () {
        $album = tagged(Album::factory()->create(), 'Original');
        $this->actingAs(addCollaborator($album, CollaboratorRole::Contributor, adminAccessUser()));

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->set('data.tag_names', ['Changed'])
            ->call('save')
            ->assertForbidden();

        expect(Tagging::namesOf($album))->toBe(['Original']);
    });

    it('records tag changes in the activity log', function () {
        actingAsRole();
        $album = Album::factory()->create();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tag_names' => ['Beach']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(ActivityEvent::where('subject_id', $album->id)->latest('id')->first()->properties['changes']['Tags']['added'])->toBe(['Beach']);
    });

    it('saves whether tags pass on to sub-albums, for staff and for an Editor', function (string $who) {
        $album = Album::factory()->create();
        $who === 'staff'
            ? actingAsRole()
            : $this->actingAs(addCollaborator($album, CollaboratorRole::Editor, adminAccessUser()));

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tags_apply_to_sub_albums' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->fresh()->tags_apply_to_sub_albums)->toBeTrue();
    })->with(['staff', 'an Editor']);

    it('allows only predefined tags while new tags are off, and keeps tags already there', function () {
        actingAsRole();
        Setting::put(SiteSettings::TAGS_ALLOW_CUSTOM, false);
        Setting::put(SiteSettings::TAGS_PREDEFINED, ['Nature']);
        $album = tagged(Album::factory()->create(), 'Older tag');

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tag_names' => ['Older tag', 'Invented']])
            ->call('save')
            ->assertHasFormErrors(['tag_names']);

        expect(Tag::where('name', 'Invented')->exists())->toBeFalse();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['tag_names' => ['Older tag', 'nature']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Tagging::namesOf($album->fresh()))->toBe(['Nature', 'Older tag']);
    });

    it('hides the tag field while tagging is off, and keeps the tags', function () {
        actingAsRole();
        $album = tagged(Album::factory()->visibility(Visibility::Public)->create(['title' => 'Holiday']), 'Beach');
        Setting::put(SiteSettings::TAGGING, false);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertFormFieldHidden('tag_names')
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Tagging::namesOf($album))->toBe(['Beach']);
    });
});

describe('the photos section', function () {
    it('shows the album photos with the cover marked', function () {
        actingAsRole();
        $album = Album::factory()->create();
        galleryPhoto($album);

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))
            ->assertOk()
            ->assertSee('Add photos')
            ->assertDontSee('Upload photos')
            ->assertSee('Cover');
    });

    it('adds photos chosen in the media picker to the album', function () {
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->create(['title' => 'Best of']);
        $inAlbum = galleryPhoto($album);
        $elsewhere = galleryPhoto(Album::factory()->create());

        albumPhotosSection($album)
            ->call('addPickedPhotos', [$elsewhere->id, $inAlbum->id])
            ->assertNotified('1 photo added to Best of');

        expect($album->photos()->pluck('photos.id')->sort()->values()->all())->toBe([$inAlbum->id, $elsewhere->id])
            ->and(ActivityEvent::where('type', ActivityType::PhotosAdded)->sole()->summary())->toBe('Ada added 1 photo to Best of');
    });

    it('ignores photos the user may not see and albums they cannot add to', function () {
        RoleModel::findOrCreate('helper')->syncPermissions([Permission::AccessAdmin->value, Permission::UploadPhotos->value]);
        $helper = tap(User::factory()->create())->assignRole('helper');
        $own = Album::factory()->for($helper, 'owner')->create();
        $hidden = galleryPhoto(Album::factory()->create());
        $this->actingAs($helper);

        albumPhotosSection($own)->call('addPickedPhotos', [$hidden->id]);
        expect($own->photos()->count())->toBe(0);

        albumPhotosSection(Album::factory()->create())->call('addPickedPhotos', [$hidden->id])->assertForbidden();
    });

    it('adds only own uploads when picking for an album reached through a role', function () {
        $user = addCollaborator($target = Album::factory()->create(), CollaboratorRole::Editor, userWithRole(Role::Uploader));
        $own = Album::factory()->for($user, 'owner')->create();
        $mine = Photo::factory()->inAlbum($own)->create(['uploader_id' => $user->id]);
        $notMine = Photo::factory()->inAlbum($own)->create(['uploader_id' => User::factory()]);
        $this->actingAs($user);

        albumPhotosSection($target)->call('addPickedPhotos', [$mine->id, $notMine->id]);

        expect($target->photos()->pluck('photos.id')->all())->toBe([$mine->id]);
    });

    it('removes photos from the album but keeps photos that are in no other album', function () {
        $admin = actingAsRole();
        $album = Album::factory()->create(['title' => 'Camp']);
        $shared = galleryPhoto($album, name: 'Shared.jpg');
        Album::factory()->create()->addPhotos($shared);
        $only = galleryPhoto($album, name: 'Only.jpg');

        albumPhotosSection($album)
            ->selectTableRecords([$shared->id, $only->id])
            ->callAction(TestAction::make('removeFromAlbum')->table()->bulk())
            ->assertNotified('1 photo removed from Camp');

        expect($album->photos()->pluck('photos.id')->all())->toBe([$only->id])
            ->and(ActivityEvent::where('type', ActivityType::PhotosRemoved)->sole()->summary())->toBe("{$admin->name} removed 1 photo from Camp");
    });

    it('lets a contributor remove their own upload without trashing it, and leaves out other people\'s', function () {
        $album = Album::factory()->create();
        $contributor = addCollaborator($album, CollaboratorRole::Contributor);
        $own = Photo::factory()->inAlbum($album)->create(['uploader_id' => $contributor->id]);
        $others = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);
        $this->actingAs($contributor);

        albumPhotosSection($album)
            ->selectTableRecords([$own->id, $others->id])
            ->callAction(TestAction::make('removeFromAlbum')->table()->bulk())
            ->assertNotified('1 photo was left out because someone else uploaded it.');

        expect($album->photos()->pluck('photos.id')->all())->toBe([$others->id])
            ->and($own->fresh()->trashed())->toBeFalse()
            ->and($own->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($contributor)->id]);
    });

    it('lets an organizer remove someone else\'s photo', function () {
        $album = Album::factory()->create();
        $others = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);
        $this->actingAs(addCollaborator($album, CollaboratorRole::Organizer));

        albumPhotosSection($album)
            ->selectTableRecords([$others->id])
            ->callAction(TestAction::make('removeFromAlbum')->table()->bulk());

        expect($album->photos()->count())->toBe(0)
            ->and($others->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($album->owner)->id]);
    });

    it('refuses reordering below Editor', function () {
        $album = Album::factory()->create(['photo_order' => PhotoOrder::Manual]);
        $photo = Photo::factory()->inAlbum($album)->create();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Organizer));

        albumPhotosSection($album)->call('reorderTable', [$photo->id])->assertForbidden();
    });

    it('opens "Add photos" on load when the site\'s Add photos button sends people here', function () {
        actingAsRole();
        $album = Album::factory()->create();
        parse_str(parse_url(RelationManager::class === '' ? '' : PhotosRelationManager::addPhotosUrl($album), PHP_URL_QUERY), $query);

        Livewire::withQueryParams($query)
            ->test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->call('mountDefaultActions')
            ->assertSet('mountedActions.0.name', 'addFromLibrary');
    });

    it('uploads photos in the album\'s picker straight into the album', function () {
        $admin = actingAsRole();
        $album = Album::factory()->create(['title' => 'Camp']);
        $manager = albumPhotosSection($album)->mountAction(TestAction::make('addFromLibrary')->table());
        $target = $manager->instance()->getMountedAction()->getModalContent()->getData()['uploadTo'];

        $photoId = uploadPhotoInChunks($target, 'lake.jpg')->assertOk()->json('photo');
        Livewire::test(MediaPicker::class, ['key' => 'album-photos', 'uploadTo' => $target])
            ->call('addUploaded', [$photoId])
            ->assertNotDispatched('album-photos-changed');
        $manager->call('addPickedPhotos', [$photoId])
            ->assertNotified('1 photo is in Camp');

        expect($target)->toBe("album:{$album->id}")
            ->and(Photo::find($photoId)->albums()->pluck('albums.id')->all())->toBe([$album->id])
            ->and(Album::inbox($admin)->photos()->count())->toBe(0)
            ->and(ActivityEvent::where('type', ActivityType::PhotosAdded)->exists())->toBeFalse();
    });

    it('adds photos uploaded in the picker to the album, out of "Unsorted uploads"', function () {
        actingAsRole();
        $album = Album::factory()->create();

        $photoId = uploadPhotoInChunks('inbox', 'lake.jpg')->assertOk()->json('photo');
        $uploaded = Livewire::test(MediaPicker::class, ['key' => 'album-photos', 'uploadTo' => 'inbox'])
            ->call('addUploaded', [$photoId])
            ->get('justUploaded');

        albumPhotosSection($album)->call('addPickedPhotos', $uploaded);

        expect($album->photos()->pluck('photos.id')->all())->toBe($uploaded)
            ->and(Photo::find($uploaded[0])->albums()->pluck('slug')->all())->toBe([$album->slug]);
    });
});

describe('the create page', function () {
    it('creates an album with the photos and cover chosen in the media picker', function () {
        $admin = actingAsRole();
        [$first, $second] = [galleryPhoto(Album::factory()->create()), galleryPhoto(Album::factory()->create())];
        $uploaded = galleryPhoto(Album::inbox($admin), name: 'Fresh.jpg');
        $cover = galleryPhoto(Album::factory()->create(), name: 'Cover.jpg');

        Livewire::test(CreateAlbum::class)
            ->set('data.title', 'Best of 2026')
            ->set('data.owner_id', $admin->id)
            ->set('data.photo_ids', [$second->id, $first->id, $uploaded->id])
            ->set('data.cover_photo_id', $cover->id)
            ->call('create')
            ->assertHasNoErrors();

        $album = Album::where('title', 'Best of 2026')->sole();
        expect($album->photos()->pluck('photos.id')->all())->toBe([$second->id, $first->id, $uploaded->id, $cover->id])
            ->and($album->cover_photo_id)->toBe($cover->id)
            ->and($uploaded->albums()->pluck('slug')->all())->toBe([$album->slug]); // left "Unsorted uploads"
    });

    it('passes the photo field\'s count and remove label to the browser in the reader\'s language', function () {
        $this->actingAs(userWithRole(Role::SuperAdmin, ['locale' => 'de']));

        $this->get(AlbumResource::getUrl('create'))
            ->assertOk()
            ->assertSee(Js::from(':count Fotos')->toHtml(), false)
            ->assertSee(Js::from(':title entfernen')->toHtml(), false);
    });

    it('invites people and groups when the album is created', function () {
        Notification::fake();
        $owner = actingAsRole(Role::Uploader);
        $person = User::factory()->create();
        $group = Group::factory()->create();

        Livewire::test(CreateAlbum::class)
            ->fillForm([
                'title' => 'New',
                'invitees' => ['user:'.$person->id, 'group:'.$group->id],
                'invitee_role' => CollaboratorRole::Organizer->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $album = Album::where('title', 'New')->sole();
        expect($album->collaboratorRole($person))->toBe(CollaboratorRole::Organizer)
            ->and($album->owner_id)->toBe($owner->id);
        $this->assertDatabaseHas('album_group_collaborators', ['album_id' => $album->id, 'group_id' => $group->id, 'role' => 'organizer']);
        Notification::assertSentTo($person, AlbumCollaboratorAdded::class);
    });

    it('offers no invitations without the permission', function () {
        $creator = tap(User::factory()->create())->givePermissionTo('access admin panel', 'create albums');
        $this->actingAs($creator);

        $this->get(AlbumResource::getUrl('create'))
            ->assertOk()
            ->assertDontSee('People and groups who work on this album with you.');
    });
});

describe('sub-albums', function () {
    it('creates a private sub-album owned by the parent\'s owner, recording who created it', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $organizer = addCollaborator($album, CollaboratorRole::Organizer);
        $this->actingAs($organizer);

        Livewire::withQueryParams(['parent' => $album->id])->test(CreateAlbum::class)
            ->fillForm(['title' => 'Day 1'])
            ->set('data.owner_id', $organizer->id)
            ->set('data.visibility', Visibility::Public->value)
            ->set('data.parent_id', null)
            ->set('data.created_by', $album->owner_id)
            ->call('create')
            ->assertHasNoFormErrors();

        $subAlbum = Album::where('title', 'Day 1')->sole();
        expect($subAlbum->owner_id)->toBe($album->owner_id)
            ->and($subAlbum->parent_id)->toBe($album->id)
            ->and($subAlbum->visibility)->toBe(Visibility::Private)
            ->and($subAlbum->collaboratorRole($organizer))->toBe(CollaboratorRole::Organizer)
            ->and($subAlbum->created_by)->toBe($organizer->id);
    });

    it('shows only the album card when creating through a role', function () {
        $album = Album::factory()->create(['title' => 'Camp']);
        $this->actingAs(addCollaborator($album, CollaboratorRole::Organizer));

        $this->get(AlbumResource::getUrl('create', ['parent' => $album->id]))
            ->assertOk()
            ->assertSee(__('New album inside :album', ['album' => 'Camp']))
            ->assertDontSee(__('Visibility & sharing'))
            ->assertDontSee(__('Inside album'));
    });

    it('forbids creating albums a member\'s role does not allow', function () {
        $album = Album::factory()->create();
        $contributor = addCollaborator($album, CollaboratorRole::Contributor);
        $trashed = Album::factory()->create();
        $editor = addCollaborator($trashed, CollaboratorRole::Editor);
        addCollaborator(Album::factory()->create(), CollaboratorRole::Editor, $editor);
        $unrelated = Album::factory()->create();

        $this->actingAs($contributor)->get(AlbumResource::getUrl('create', ['parent' => $album->id]))->assertForbidden();

        $trashed->delete();
        $this->actingAs($editor);
        $this->get(AlbumResource::getUrl('create'))->assertForbidden();
        $this->get(AlbumResource::getUrl('create', ['parent' => $trashed->id]))->assertForbidden();
        $this->get(AlbumResource::getUrl('create', ['parent' => $unrelated->id]))->assertForbidden();
    });

    it('moves a sub-album to the trash from its page and restores it from the trash', function () {
        $album = Album::factory()->create();
        $creator = addCollaborator($album, CollaboratorRole::Organizer);
        $subAlbum = subAlbumCreatedBy($creator, $album);

        albumPage($subAlbum)->assertActionVisible('delete')->callAction('delete');

        expect($subAlbum->fresh()->trashed())->toBeTrue();

        Livewire::test(ListTrashedAlbums::class)
            ->assertCanSeeTableRecords([$subAlbum])
            ->callAction(TestAction::make('restore')->table($subAlbum));

        expect($subAlbum->fresh()->trashed())->toBeFalse();
    });

    it('keeps a trashed sub-album out of other collaborators\' trash', function () {
        $album = Album::factory()->create();
        $creator = addCollaborator($album, CollaboratorRole::Organizer);
        $other = addCollaborator($album, CollaboratorRole::Editor);
        $subAlbum = subAlbumCreatedBy($creator, $album);
        $subAlbum->delete();
        $this->actingAs($other);

        Livewire::test(ListTrashedAlbums::class)->assertCanNotSeeTableRecords([$subAlbum]);
    });

    it('deletes the album for good but never the photos in it', function () {
        $album = Album::factory()->create();
        $creator = addCollaborator($album, CollaboratorRole::Organizer);
        $subAlbum = subAlbumCreatedBy($creator, $album);
        $ownersPhoto = galleryPhoto($subAlbum, $album->owner);
        $ownUpload = galleryPhoto($subAlbum, $creator);
        $subAlbum->delete();
        $this->actingAs($creator);

        Livewire::test(ListTrashedAlbums::class)
            ->callAction(TestAction::make('forceDelete')->table($subAlbum), ['delete_photos' => true]);

        expect(Album::withTrashed()->find($subAlbum->id))->toBeNull()
            ->and($ownersPhoto->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($album->owner)->id])
            ->and($ownUpload->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($creator)->id]);
    });
});

describe('the trash', function () {
    beforeEach(fn () => actingAsRole(Role::SuperAdmin));

    it('shows the trash button and page following what the trash holds', function () {
        $kept = Album::factory()->create();

        Livewire::test(ListAlbums::class)->assertActionHidden('trash');
        $this->get(AlbumResource::getUrl('trash'))->assertOk()->assertSee('The trash is empty');

        $trashed = tap(Album::factory()->create())->delete();

        Livewire::test(ListAlbums::class)
            ->assertActionVisible('trash')
            ->assertActionHasUrl('trash', AlbumResource::getUrl('trash'))
            ->assertCanSeeTableRecords([$kept])
            ->assertCanNotSeeTableRecords([$trashed]);
        Livewire::test(ListTrashedAlbums::class)
            ->assertCanSeeTableRecords([$trashed])
            ->assertCanNotSeeTableRecords([$kept]);
    });

    it('offers only "Move to trash" on the lists and only restore or delete permanently in the trash', function () {
        foreach (['restore', 'forceDelete'] as $action) {
            Livewire::test(ListAlbums::class)->assertActionDoesNotExist(TestAction::make($action)->table()->bulk());
        }
        Livewire::test(ListTrashedAlbums::class)->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
        Livewire::test(ListTrashedAlbums::class)->assertActionExists(TestAction::make('restore')->table()->bulk());
    });

    it('says which photos disappear from the site when an album goes to the trash', function () {
        $album = Album::factory()->create();
        Photo::factory()->count(2)->inAlbum($album)->create();
        Photo::factory()->inAlbum($album)->inAlbum(Album::factory()->create())->create();

        $page = albumPage($album)->mountAction('delete');

        expect($page->instance()->getMountedAction()->getModalDescription())
            ->toBe('2 photos are in no other album. They are hidden on the site until you restore the album. You can restore it from the trash.');
    });

    it('moves the albums inside an album to the trash with it and brings them back together', function () {
        $camp = Album::factory()->create(['title' => 'Camp']);
        Album::factory()->create(['title' => 'Day 3', 'parent_id' => $camp->id]);
        $evening = Album::factory()->create(['title' => 'Evening', 'parent_id' => Album::where('title', 'Day 3')->first()->id]);
        tap(Album::factory()->create(['title' => 'Rejects', 'parent_id' => $camp->id]))->delete();
        $this->travel(1)->minutes();
        $other = Album::factory()->create(['title' => 'Other']);

        $page = albumPage($camp)
            ->mountAction(DeleteAction::class)
            ->assertActionMounted(DeleteAction::class);
        expect($page->instance()->getMountedAction()->getModalDescription())
            ->toBe('The 2 albums inside it go to the trash too, and come back when you restore this one. You can restore it from the trash.');
        $page->callMountedAction();

        expect(Album::onlyTrashed()->pluck('title')->sort()->values()->all())->toBe(['Camp', 'Day 3', 'Evening', 'Rejects'])
            ->and($other->fresh()->trashed())->toBeFalse();

        albumPage($camp)->callAction(RestoreAction::class);

        expect(Album::onlyTrashed()->pluck('title')->all())->toBe(['Rejects'])
            ->and($evening->fresh()->trashed())->toBeFalse();
    });

    it('links the album page to the album on the site, except while it is in the trash', function () {
        $album = Album::factory()->create();

        albumPage($album)
            ->assertActionHasUrl('open', route('albums.show', $album))
            ->assertActionShouldOpenUrlInNewTab('open');

        $album->delete();

        albumPage($album)->assertActionHidden('open');
    });

    it('opens an album in the trash read-only, with restore and delete permanently', function () {
        $album = tap(Album::factory()->create(['title' => 'Camp']))->delete();

        albumPage($album)
            ->assertSee('This album is in the trash and hidden on the site. Restore it to change it.')
            ->assertActionHasIcon('restore', ActionIcons::RESTORE)
            ->assertActionHasColor('restore', 'gray')
            ->assertActionHasIcon('forceDelete', ActionIcons::DELETE)
            ->assertActionHasColor('forceDelete', 'danger')
            ->fillForm(['title' => 'Renamed'])
            ->call('save');
        albumPhotosSection($album)->assertActionHidden(TestAction::make('addFromLibrary')->table());

        expect($album->fresh()->title)->toBe('Camp');
    });
});

describe('deleting permanently', function () {
    it('moves sub-albums up and photos in no other album to "Unsorted uploads" by default', function () {
        actingAsRole();
        $camp = Album::factory()->create(['title' => 'Camp']);
        $day = tap(Album::factory()->create(['title' => 'Day 3', 'parent_id' => $camp->id]))->delete();
        $evening = Album::factory()->create(['title' => 'Evening', 'parent_id' => $day->id]);
        $onlyHere = Photo::factory()->inAlbum($day)->create();
        $alsoElsewhere = Photo::factory()->inAlbum($day)->inAlbum($camp)->create();

        $page = Livewire::test(EditAlbum::class, ['record' => $day->getRouteKey()])
            ->mountAction(ForceDeleteAction::class);
        $notice = collect($page->instance()->getSchema('mountedActionSchema0')->getFlatComponents())
            ->first(fn ($component) => $component instanceof Text)
            ->getContent();
        expect($notice)->toBe('1 photo is in no other album. It moves to "Unsorted uploads".');

        $page->callMountedAction()->assertHasNoFormErrors();

        expect(Album::withTrashed()->find($day->id))->toBeNull()
            ->and($evening->fresh())->parent_id->toBe($camp->id)
            ->and($evening->fresh()->depth())->toBe(1)
            ->and($onlyHere->fresh()->albums->pluck('system_album')->all())->toBe([Album::INBOX])
            ->and($alsoElsewhere->fresh()->albums->modelKeys())->toBe([$camp->id])
            // They go to the deleted album's owner, not to the person deleting it.
            ->and(Album::inbox($day->owner)->photos()->count())->toBe(1);
    });

    it('deletes those photos instead when asked, except photos used on a page', function () {
        actingAsRole();
        $album = tap(Album::factory()->create())->delete();
        $onlyHere = Photo::factory()->inAlbum($album)->create();
        $onPage = Photo::factory()->inAlbum($album)->create();
        Page::factory()->create(['body' => customBlockMarkup('photo', ['photo_id' => $onPage->id])]);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->callAction(ForceDeleteAction::class, ['delete_photos' => true])
            ->assertHasNoFormErrors();

        expect(Photo::withTrashed()->find($onlyHere->id))->toBeNull()
            ->and($onPage->fresh()->albums->pluck('system_album')->all())->toBe([Album::INBOX]);
    });

    it('deletes nested albums together in bulk', function () {
        actingAsRole();
        $parent = tap(Album::factory()->create())->delete();
        $child = tap(Album::factory()->create(['parent_id' => $parent->id]))->delete();
        $photo = Photo::factory()->inAlbum($parent)->inAlbum($child)->create();

        Livewire::test(ListTrashedAlbums::class)
            ->selectTableRecords([$child->id, $parent->id])
            ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk())
            ->assertHasNoFormErrors();

        expect(Album::withTrashed()->whereKey([$parent->id, $child->id])->exists())->toBeFalse()
            ->and($photo->fresh()->albums->pluck('system_album')->all())->toBe([Album::INBOX]);
    });

    it('lets only people who may delete an album restore or delete it permanently', function () {
        $owner = User::factory()->create();
        $album = tap(Album::factory()->for($owner, 'owner')->create())->delete();

        $this->actingAs(userWithRole(Role::Moderator));
        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertActionHidden(ForceDeleteAction::class)
            ->assertActionHidden(RestoreAction::class);

        $this->actingAs($owner);
        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertActionVisible(ForceDeleteAction::class)
            ->assertActionVisible(RestoreAction::class);
    });
});

describe('leaving', function () {
    it('removes a direct role and ends the access', function () {
        $album = Album::factory()->create(['title' => 'Camp']);
        $user = addCollaborator($album, CollaboratorRole::Editor);
        $this->actingAs($user);

        albumPage($album)
            ->callAction('leaveAlbum')
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('album_collaborators', ['album_id' => $album->id, 'user_id' => $user->id]);
        expect(session('collaboration_status'))->toBe('You left "Camp".');
    });

    it('says so and names the group when access remains', function () {
        $album = Album::factory()->create(['title' => 'Camp']);
        $user = addCollaborator($album, CollaboratorRole::Editor);
        $group = addGroupCollaborator($album, CollaboratorRole::Contributor, $user);
        $group->update(['name' => 'Scout leaders']);
        $this->actingAs($user);

        albumPage($album)
            ->callAction('leaveAlbum')
            ->assertNotified('You left "Camp", but you still have access through the group Scout leaders.')
            ->assertRedirect(AlbumResource::getUrl('edit', ['record' => $album]));

        expect($album->collaboratorRole($user))->toBe(CollaboratorRole::Contributor);
    });

    it('offers no leaving when the role only comes through a group, and names it', function () {
        $album = Album::factory()->create();
        $user = User::factory()->create();
        $group = addGroupCollaborator($album, CollaboratorRole::Editor, $user);
        $group->update(['name' => 'Scout leaders']);
        $this->actingAs($user);

        albumPage($album)
            ->assertActionHidden('leaveAlbum')
            ->assertSee(__('You have this access through the group :groups.', ['groups' => 'Scout leaders']));
    });
});

describe('share notices', function () {
    it('tells people who get access to a restricted album, directly or through a group', function () {
        mailWorks();
        $ada = actingAsRole(attributes: ['name' => 'Ada']);
        [$direct, $member, $already] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $group = Group::factory()->create();
        $group->users()->attach([$member->id, $ada->id]);
        $album = Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Camp']);
        $album->sharedUsers()->attach($already);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm([
                'shared_with' => ['user:'.$already->id, 'user:'.$direct->id, 'group:'.$group->id],
                'notify_shared' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('2 people will get an e-mail shortly');

        Notification::assertSentTo([$direct, $member], AlbumShared::class, fn (AlbumShared $mail) => $mail->album->is($album) && $mail->sharedBy === 'Ada');
        Notification::assertNotSentTo([$already, $ada], AlbumShared::class);
    });

    it('stays quiet when the switch is off or the album is not restricted', function () {
        mailWorks();
        actingAsRole(attributes: ['name' => 'Ada']);
        $person = User::factory()->create();

        $restricted = Album::factory()->visibility(Visibility::Restricted)->create();
        Livewire::test(EditAlbum::class, ['record' => $restricted->getRouteKey()])
            ->fillForm(['shared_with' => ['user:'.$person->id], 'notify_shared' => false])
            ->call('save');

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Open day', 'visibility' => Visibility::Public, 'notify_shared' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Notification::assertNothingSent();
    });

    it('tells people when a new album is created already shared with them', function () {
        mailWorks();
        actingAsRole(attributes: ['name' => 'Ada']);
        $person = User::factory()->create();

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Camp', 'visibility' => Visibility::Restricted, 'shared_with' => ['user:'.$person->id], 'notify_shared' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($person, AlbumShared::class);
    });

    it('tells people in the gallery when e-mail is not set up', function () {
        $ada = actingAsRole(attributes: ['name' => 'Ada']);
        $grace = User::factory()->create();
        $album = Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Camp']);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['shared_with' => ['user:'.$grace->id], 'notify_shared' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('1 person was told in the gallery');

        expect($grace->notifications()->sole())
            ->type->toBe(AlbumShared::class)
            ->data->toMatchArray(['data' => ['album' => $album->id, 'slug' => $album->slug, 'title' => 'Camp', 'by' => $ada->name]]);
    });
});

describe('sharing by e-mail', function () {
    it('adds an address without an account as a guest and queues their invitation, whatever the notify switch says', function () {
        deliverToMemory();
        actingAsRole(attributes: ['name' => 'Ada']);
        $album = Album::factory()->visibility(Visibility::Restricted)->create();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['shared_with' => ['email:new.guest@example.com'], 'notify_shared' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->guests()->sole())
            ->email->toBe('new.guest@example.com')
            ->invited_by->not->toBeNull()
            ->and(mailJobs())->toBe(1)
            ->and($album->sharedUsers()->count())->toBe(0);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertFormSet(['shared_with' => ['email:new.guest@example.com']])
            ->call('save');

        expect($album->guests()->count())->toBe(1)
            ->and(mailJobs())->toBe(1);
    });

    it('shares with the account instead when the address belongs to one, whatever its case', function () {
        deliverToMemory();
        actingAsRole();
        $grace = User::factory()->create(['email' => 'Grace@Example.com']);
        $album = Album::factory()->visibility(Visibility::Restricted)->create();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['shared_with' => ['email:grace@example.com']])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($album->sharedUsers()->pluck('users.id')->all())->toBe([$grace->id])
            ->and($album->guests()->count())->toBe(0)
            ->and(mailJobs())->toBe(0);
    });

    it('ends the guest\'s access when the address is taken off the list', function () {
        deliverToMemory();
        actingAsRole();
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $page = fn () => Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()]);

        $page()->fillForm(['shared_with' => ['email:a@example.com', 'email:b@example.com']])->call('save');
        expect($album->guests()->pluck('email')->all())->toEqualCanonicalizing(['a@example.com', 'b@example.com']);

        $page()->fillForm(['shared_with' => ['email:b@example.com']])->call('save');

        expect($album->guests()->pluck('email')->all())->toBe(['b@example.com'])
            ->and(mailJobs())->toBe(2);
    });

    it('does not invite anyone new while sharing by e-mail is off, but lets the list shrink', function () {
        deliverToMemory();
        actingAsRole();
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        AlbumGuest::factory()->for($album)->create(['email' => 'old@example.com']);
        Setting::put(SiteSettings::ALBUM_GUESTS, false);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['shared_with' => ['email:old@example.com', 'email:new@example.com']])
            ->call('save');

        expect($album->guests()->pluck('email')->all())->toBe(['old@example.com'])
            ->and(mailJobs())->toBe(0);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['shared_with' => []])
            ->call('save');

        expect($album->guests()->count())->toBe(0);
    });

    it('offers an address in the picker only when it belongs to no account and guests are on', function () {
        deliverToMemory();
        actingAsRole();
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $picker = fn () => Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->instance()->form->getComponent(fn ($component) => $component instanceof Select && $component->getName() === 'shared_with');

        expect($picker()->getSearchResults(' New@Example.com '))->toHaveKey('email:new@example.com')
            ->and($picker()->getSearchResults('not an address'))->toBe([])
            ->and(array_keys($picker()->getSearchResults('Grace@Example.com')))->toBe(['user:'.User::where('email', 'grace@example.com')->value('id')]);

        Setting::put(SiteSettings::ALBUM_GUESTS, false);
        expect($picker()->getSearchResults('new@example.com'))->toBe([]);

        Setting::put(SiteSettings::ALBUM_GUESTS, true);
        config(['mail.default' => 'log']);
        expect($picker()->getSearchResults('new@example.com'))->toBe([]);
    });
});
