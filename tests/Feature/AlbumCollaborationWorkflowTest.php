<?php

use App\Enums\CollaboratorRole;
use App\Enums\PhotoOrder;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Forms\Components\PeopleAndGroupsSelect;
use App\Filament\GlobalSearch\AdminSearchProvider;
use App\Filament\Pages\Documentation;
use App\Filament\Pages\Plugins;
use App\Filament\Pages\Themes;
use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\Pages\ListTrashedAlbums;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Livewire\MediaPicker;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AlbumCollaboratorAdded;
use App\Notifications\AlbumShared;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('gallery');
    Storage::fake('local');
});

describe('entering the admin', function () {
    it('takes a member with a role from the admin home to the albums list', function () {
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Contributor));

        $this->get('/admin')->assertRedirect(AlbumResource::getUrl());
    });

    it('keeps the admin closed to a member without a role', function () {
        Album::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(AlbumResource::getUrl())->assertForbidden();
    });

    it('closes the admin again when the last role is removed', function () {
        $album = Album::factory()->create();
        $member = addCollaborator($album, CollaboratorRole::Editor);
        $this->actingAs($member)->get(AlbumResource::getUrl())->assertOk();

        $album->collaborators()->detach($member->id);

        $this->get(AlbumResource::getUrl())->assertForbidden();
    });

    it('keeps every other admin section closed to a member with a role', function (Closure $url) {
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        $this->get($url())->assertForbidden();
    })->with([
        'media' => [fn () => PhotoResource::getUrl()],
        'documentation' => [fn () => Documentation::getUrl()],
        'users' => [fn () => UserResource::getUrl()],
        'groups' => [fn () => GroupResource::getUrl()],
        'roles' => [fn () => RoleResource::getUrl()],
        'pages' => [fn () => PageResource::getUrl()],
        'activity' => [fn () => ActivityEventResource::getUrl()],
        'settings' => [fn () => GeneralSettings::getUrl()],
        'themes' => [fn () => Themes::getUrl()],
        'plugins' => [fn () => Plugins::getUrl()],
    ]);

    it('shows a member with a role only the albums section and no search', function () {
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        $this->get(AlbumResource::getUrl())
            ->assertOk()
            ->assertSee(AlbumResource::getUrl(), false)
            ->assertDontSee(PhotoResource::getUrl(), false)
            ->assertDontSee(Documentation::getUrl(), false)
            ->assertDontSeeLivewire('filament.livewire.global-search');
    });

    it('finds nothing through the search for a member with a role', function () {
        $this->actingAs(addCollaborator(Album::factory()->create(['title' => 'Camp']), CollaboratorRole::Editor));

        expect((new AdminSearchProvider)->getResults('Camp')->getCategories())->toBeEmpty();
    });

    it('offers the admin in the site menu to a member with a role', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $member = addCollaborator($album, CollaboratorRole::Contributor);

        $this->actingAs($member)->get(route('home'))->assertSee(url('/admin'), false);
        $this->actingAs(User::factory()->create())->get(route('home'))->assertDontSee(url('/admin'), false);
    });
});

describe('the albums list', function () {
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
});

describe('the album page by role', function () {
    it('opens for every role with their role named', function (CollaboratorRole $role) {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator($album, $role));

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))
            ->assertOk()
            ->assertSee(__('Your role: :role', ['role' => $role->getLabel()]));
    })->with(CollaboratorRole::cases());

    it('stays closed to someone without a role', function () {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))->assertNotFound();
    });

    it('stays closed for trashed albums', function () {
        $album = Album::factory()->create();
        $member = addCollaborator($album, CollaboratorRole::Editor);
        addCollaborator(Album::factory()->create(), CollaboratorRole::Contributor, $member);
        $this->actingAs($member);
        $album->delete();

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))->assertNotFound();
    });

    it('never offers trash, restore or delete', function (CollaboratorRole $role) {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator($album, $role));

        albumPage($album)
            ->assertActionHidden('delete')
            ->assertActionHidden('restore')
            ->assertActionHidden('forceDelete');
    })->with(CollaboratorRole::cases());

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

    it('never sends the password hash to the browser', function () {
        $album = Album::factory()->create(['visibility' => Visibility::Registered, 'password' => 'old secret']);
        $hash = $album->fresh()->password;
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        $this->get(AlbumResource::getUrl('edit', ['record' => $album]))->assertOk()->assertDontSee($hash, false);
    });

    it('keeps the password when the field stays empty', function () {
        $album = Album::factory()->create(['visibility' => Visibility::Registered, 'password' => 'old secret']);
        $fingerprint = $album->fresh()->passwordFingerprint();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

        albumPage($album)->fillForm(['title' => 'New title'])->call('save')->assertHasNoFormErrors();

        expect($album->fresh()->passwordFingerprint())->toBe($fingerprint);
    });

    it('locks out earlier visitors when an Editor changes the password', function () {
        $album = Album::factory()->create(['visibility' => Visibility::Registered, 'password' => 'old secret']);
        $fingerprint = $album->fresh()->passwordFingerprint();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));

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

describe('the cover', function () {
    it('accepts a photo already in the album', function () {
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
        $editor = tap(User::factory()->create())->assignRole(Role::Uploader->value);
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

describe('adding photos', function () {
    it('lets a member without admin access upload into an album they work on', function () {
        $album = Album::factory()->create();
        $member = addCollaborator($album, CollaboratorRole::Contributor);
        $this->actingAs($member);

        uploadPhotoInChunks('album:'.$album->id)->assertOk()->assertJsonPath('done', true);

        expect($album->photos()->sole()->uploader_id)->toBe($member->id);
    });

    it('refuses the upload for a member without a role', function () {
        $album = Album::factory()->create();
        $this->actingAs(User::factory()->create());

        uploadPhotoInChunks('album:'.$album->id)->assertForbidden();

        expect($album->photos()->count())->toBe(0);
    });

    it('keeps the inbox and page images closed to members with a role', function (string $target) {
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        uploadPhotoInChunks($target)->assertForbidden();
    })->with(['inbox', 'page-images']);

    it('refuses uploads into a trashed album', function () {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Editor));
        $album->delete();

        uploadPhotoInChunks('album:'.$album->id)->assertForbidden();
    });

    it('offers "Add photos" in the Photos section to every role', function (CollaboratorRole $role) {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator($album, $role));

        albumPhotosSection($album)->assertActionVisible(TestAction::make('addFromLibrary')->table());
    })->with(CollaboratorRole::cases());

    it('keeps the picker to this album and the person\'s own uploads', function () {
        $album = Album::factory()->create();
        $member = addCollaborator($album, CollaboratorRole::Editor);
        $inAlbum = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);
        $own = Photo::factory()->inAlbum(Album::factory()->create())->create(['uploader_id' => $member->id]);
        $public = Album::factory()->visibility(Visibility::Public)->create();
        $elsewhere = Photo::factory()->inAlbum($public)->create(['uploader_id' => $public->owner_id]);
        $this->actingAs($member);

        $picker = Livewire::test(MediaPicker::class, ['key' => 'cover', 'limitToAlbumId' => $album->id])
            ->assertCanSeeTableRecords([$inAlbum, $own])
            ->assertCanNotSeeTableRecords([$elsewhere]);

        expect($picker->instance()->getTable()->getFilter('uploader'))->toBeNull()
            ->and($picker->instance()->getTable()->getFilter('albums'))->toBeNull();
    });

    it('refuses the picker without admin access or a role on the album', function () {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        Livewire::test(MediaPicker::class, ['key' => 'x'])->assertForbidden();
        Livewire::test(MediaPicker::class, ['key' => 'x', 'limitToAlbumId' => $album->id])->assertForbidden();
    });
});

describe('removing photos', function () {
    it('lets a contributor remove their own upload without trashing it', function () {
        $album = Album::factory()->create();
        $contributor = addCollaborator($album, CollaboratorRole::Contributor);
        $photo = Photo::factory()->inAlbum($album)->create(['uploader_id' => $contributor->id]);
        $this->actingAs($contributor);

        albumPhotosSection($album)
            ->selectTableRecords([$photo->id])
            ->callAction(TestAction::make('removeFromAlbum')->table()->bulk());

        expect($album->photos()->count())->toBe(0)
            ->and($photo->fresh()->trashed())->toBeFalse()
            ->and($photo->fresh()->albums->pluck('id')->all())->toBe([Album::inbox($contributor)->id]);
    });

    it('leaves out someone else\'s photo for a contributor and says so', function () {
        $album = Album::factory()->create();
        $contributor = addCollaborator($album, CollaboratorRole::Contributor);
        $own = Photo::factory()->inAlbum($album)->create(['uploader_id' => $contributor->id]);
        $others = Photo::factory()->inAlbum($album)->create(['uploader_id' => $album->owner_id]);
        $this->actingAs($contributor);

        albumPhotosSection($album)
            ->selectTableRecords([$own->id, $others->id])
            ->callAction(TestAction::make('removeFromAlbum')->table()->bulk())
            ->assertNotified('1 photo was left out because someone else uploaded it.');

        expect($album->photos()->pluck('photos.id')->all())->toBe([$others->id]);
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

    it('never offers the trash in the Photos section through a role', function (CollaboratorRole $role) {
        $album = Album::factory()->create();
        Photo::factory()->inAlbum($album)->create();
        $this->actingAs(addCollaborator($album, $role));

        albumPhotosSection($album)->assertActionHidden(TestAction::make('delete')->table()->bulk());
    })->with(CollaboratorRole::cases());

    it('refuses reordering below Editor', function () {
        $album = Album::factory()->create(['photo_order' => PhotoOrder::Manual]);
        $photo = Photo::factory()->inAlbum($album)->create();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Organizer));

        albumPhotosSection($album)->call('reorderTable', [$photo->id])->assertForbidden();
    });
});

describe('sub-albums', function () {
    it('creates a private sub-album owned by the parent\'s owner', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $organizer = addCollaborator($album, CollaboratorRole::Organizer);
        $this->actingAs($organizer);

        Livewire::withQueryParams(['parent' => $album->id])->test(CreateAlbum::class)
            ->fillForm(['title' => 'Day 1'])
            ->set('data.owner_id', $organizer->id)
            ->set('data.visibility', Visibility::Public->value)
            ->set('data.parent_id', null)
            ->call('create')
            ->assertHasNoFormErrors();

        $subAlbum = Album::where('title', 'Day 1')->sole();
        expect($subAlbum->owner_id)->toBe($album->owner_id)
            ->and($subAlbum->parent_id)->toBe($album->id)
            ->and($subAlbum->visibility)->toBe(Visibility::Private)
            ->and($subAlbum->collaboratorRole($organizer))->toBe(CollaboratorRole::Organizer);
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

    it('forbids contributors to create sub-albums', function () {
        $album = Album::factory()->create();
        $this->actingAs(addCollaborator($album, CollaboratorRole::Contributor));

        $this->get(AlbumResource::getUrl('create', ['parent' => $album->id]))->assertForbidden();
    });

    it('forbids a member with a role to create a top-level album', function () {
        $this->actingAs(addCollaborator(Album::factory()->create(), CollaboratorRole::Editor));

        $this->get(AlbumResource::getUrl('create'))->assertForbidden();
    });

    it('forbids a sub-album in a trashed or unrelated album', function () {
        $trashed = Album::factory()->create();
        $member = addCollaborator($trashed, CollaboratorRole::Editor);
        addCollaborator(Album::factory()->create(), CollaboratorRole::Editor, $member);
        $unrelated = Album::factory()->create();
        $this->actingAs($member);
        $trashed->delete();

        $this->get(AlbumResource::getUrl('create', ['parent' => $trashed->id]))->assertForbidden();
        $this->get(AlbumResource::getUrl('create', ['parent' => $unrelated->id]))->assertForbidden();
    });
});

describe('deleting sub-albums you created', function () {

    it('records who created a sub-album and ignores a crafted creator', function () {
        $album = Album::factory()->create();
        $organizer = addCollaborator($album, CollaboratorRole::Organizer);
        $this->actingAs($organizer);

        Livewire::withQueryParams(['parent' => $album->id])->test(CreateAlbum::class)
            ->fillForm(['title' => 'Day 1'])
            ->set('data.created_by', $album->owner_id)
            ->call('create');

        expect(Album::where('title', 'Day 1')->sole()->created_by)->toBe($organizer->id);
    });

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

    it('moves it to the trash from the album page and restores it from the trash', function () {
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

describe('the create page', function () {
    it('invites people and groups when the album is created', function () {
        Notification::fake();
        $owner = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $person = User::factory()->create();
        $group = Group::factory()->create();
        $this->actingAs($owner);

        Livewire::test(CreateAlbum::class)
            ->fillForm([
                'title' => 'New',
                'invitees' => ['user:'.$person->id, 'group:'.$group->id],
                'invitee_role' => CollaboratorRole::Organizer->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $album = Album::where('title', 'New')->sole();
        expect($album->collaboratorRole($person))->toBe(CollaboratorRole::Organizer);
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

describe('the site', function () {
    it('links collaborators to the admin instead of offering a second workflow', function () {
        $album = Album::factory()->create();
        $member = addCollaborator($album, CollaboratorRole::Editor);

        $this->actingAs($member)->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee(__('Manage album'))
            ->assertSee(AlbumResource::getUrl('edit', ['record' => $album]), false)
            ->assertDontSee('ogPhotoUploader')
            ->assertDontSee('Leave album');
    });

    it('shows no management link to other visitors', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $collaborator = addCollaborator($album, CollaboratorRole::Contributor, User::factory()->create(['name' => 'Zora Collaborator']));

        $this->actingAs(User::factory()->create())->get(route('albums.show', $album))
            ->assertOk()
            ->assertDontSee(__('Manage album'))
            ->assertDontSee($collaborator->name);
    });

    it('lists direct, group and inherited albums under "Shared with you" with the role', function () {
        $user = User::factory()->create();
        $direct = Album::factory()->create(['title' => 'Direct album']);
        addCollaborator($direct, CollaboratorRole::Contributor, $user);
        $throughGroup = Album::factory()->create(['title' => 'Group album']);
        addGroupCollaborator($throughGroup, CollaboratorRole::Organizer, $user);
        $parent = Album::factory()->create(['title' => 'Parent album']);
        addCollaborator($parent, CollaboratorRole::Editor, $user);
        Album::factory()->for($parent->owner, 'owner')->create(['title' => 'Inherited album', 'parent_id' => $parent->id]);
        Album::factory()->create(['title' => 'Unrelated album']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(['Shared with you', 'Manage albums', 'Direct album', 'Group album', 'Inherited album', 'Your role: Contributor', 'Your role: Organizer', 'Your role: Editor'])
            ->assertDontSee('Unrelated album');
    });
});
