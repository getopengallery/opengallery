<?php

use App\Enums\Permission;
use App\Enums\PhotoOrder;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\Pages\ListTrashedAlbums;
use App\Filament\Resources\Photos\PhotoResource;
use App\Models\Album;
use App\Models\Page;
use App\Models\Photo;
use App\Models\Role as RoleModel;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

describe('hierarchy', function () {
    it('lists sub-albums directly under their parent', function () {
        actingAsRole();
        $camp = Album::factory()->create(['title' => 'Camp']);
        $day = Album::factory()->create(['title' => 'Day 3', 'parent_id' => $camp->id]);
        $autumn = Album::factory()->create(['title' => 'Autumn']);
        $zoo = Album::factory()->create(['title' => 'Zoo']);

        Livewire::test(ListAlbums::class)->assertCanSeeTableRecords([$autumn, $camp, $day, $zoo], inOrder: true);
        expect($day->fresh()->depth())->toBe(1);
    });

    it('moves sub-albums along when their parent is renamed or moved', function () {
        $a = Album::factory()->create(['title' => 'A']);
        $b = Album::factory()->create(['title' => 'B']);
        $child = Album::factory()->create(['title' => 'Child', 'parent_id' => $a->id]);

        $a->update(['parent_id' => $b->id]);

        expect($child->fresh()->tree_path)->toStartWith($a->fresh()->tree_path)
            ->and($a->fresh()->tree_path)->toStartWith($b->fresh()->tree_path)
            ->and($child->fresh()->depth())->toBe(2);
    });

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
});

it('shows photos in the order chosen for the album', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();
    [$b, $c, $a] = [galleryPhoto($album, name: 'Banana.jpg', width: 160, height: 120), galleryPhoto($album, name: 'Cherry.jpg', width: 160, height: 120), galleryPhoto($album, name: 'Apple.jpg', width: 160, height: 120)];

    $album->update(['photo_order' => PhotoOrder::TitleAsc]);
    $this->get(route('albums.show', $album))->assertSeeInOrder(['Apple', 'Banana', 'Cherry']);

    $album->update(['photo_order' => PhotoOrder::TitleDesc]);
    $this->get(route('albums.show', $album))->assertSeeInOrder(['Cherry', 'Banana', 'Apple']);
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

it('lets visitors download only while the album is not private', function () {
    actingAsRole();
    Setting::put(SiteSettings::DOWNLOADS_ENABLED, true);
    $album = Album::factory()->visibility(Visibility::Public)->create();

    Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
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

describe('password', function () {
    it('asks visitors for the password before showing the album and its photos', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create(['password' => 'lake-2026']);
        $thumb = route('media.show', [galleryPhoto($album, name: 'Lake.jpg', width: 160, height: 120)->original(), 'thumb']);

        $this->get(route('albums.show', $album))->assertStatus(401)->assertSee('Enter its password');
        $this->get($thumb)->assertNotFound();

        $this->post(route('albums.unlock', $album), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post(route('albums.unlock', $album), ['password' => 'lake-2026'])->assertRedirect(route('albums.show', $album));

        $this->get(route('albums.show', $album))->assertOk()->assertSee($thumb, false);
        $this->get($thumb)->assertOk();
    });

    it('never asks the owner or people with direct access', function () {
        $member = User::factory()->create();
        $album = Album::factory()->visibility(Visibility::Restricted)->create(['password' => 'secret']);
        $album->sharedUsers()->attach($member);

        $this->actingAs($album->owner)->get(route('albums.show', $album))->assertOk();
        $this->actingAs($member)->get(route('albums.show', $album))->assertOk();
    });

    it('locks visitors out again when the password changes', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create(['password' => 'first']);
        $this->post(route('albums.unlock', $album), ['password' => 'first']);
        $this->get(route('albums.show', $album))->assertOk();

        $album->update(['password' => 'second']);

        $this->get(route('albums.show', $album))->assertStatus(401);
    });

    it('is set, kept and removed from the edit page', function () {
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
});

describe('end date', function () {
    it('ends public access and share links, but not direct access', function () {
        $this->travelTo(now());
        $public = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Old summer', 'access_expires_at' => now()->addDay()]);
        $link = Album::factory()->visibility(Visibility::Unlisted)->create(['access_expires_at' => now()->addDay()]);
        $member = User::factory()->create();
        $restricted = Album::factory()->visibility(Visibility::Restricted)->create(['access_expires_at' => now()->addDay()]);
        $restricted->sharedUsers()->attach($member);

        $this->get(route('home'))->assertSee('Old summer');
        $this->travel(2)->days();

        $this->get(route('home'))->assertDontSee('Old summer');
        $this->get(route('albums.show', $public))->assertNotFound();
        $this->get(route('albums.shared', $link->share_token))->assertStatus(410)->assertSee('This link has expired');
        $this->actingAs($public->owner)->get(route('albums.show', $public))->assertOk();
        $this->actingAs($member)->get(route('albums.show', $restricted))->assertOk();
    });
});

describe('cover', function () {
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
});

describe('uploaders', function () {
    it('lets the built-in Uploader role create albums in the admin and see only their own', function () {
        $uploader = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $this->actingAs($uploader);
        $others = Album::factory()->visibility(Visibility::Registered)->create();

        $this->get(AlbumResource::getUrl())->assertOk();

        Livewire::test(CreateAlbum::class)
            ->fillForm(['title' => 'Summer camp'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(ListAlbums::class)
            ->assertCanSeeTableRecords([Album::where('title', 'Summer camp')->sole()])
            ->assertCanNotSeeTableRecords([$others]);
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

    it('greets uploaders on the dashboard with their albums and ways to add more', function () {
        $uploader = tap(User::factory()->create())->assignRole(Role::Uploader->value);
        $camp = Album::factory()->for($uploader, 'owner')->create(['title' => 'Summer camp']);
        Album::inbox($uploader);
        Album::factory()->create(['title' => 'Someone else\'s']);

        $this->actingAs($uploader)->get('/admin')
            ->assertOk()
            ->assertSee('Your albums')
            ->assertSee(AlbumResource::getUrl('edit', ['record' => $camp]))
            ->assertDontSee('Unsorted uploads')
            ->assertDontSee('Someone else&#039;s', false)
            ->assertSee(AlbumResource::getUrl('create'))
            ->assertSee(PhotoResource::getUrl('index', ['action' => 'uploadPhotos']));
    });

    it('leaves the albums panel out for staff who neither create albums nor upload', function () {
        RoleModel::findOrCreate('user-manager')->syncPermissions([Permission::AccessAdmin->value, Permission::ManageUsers->value]);

        $this->actingAs(tap(User::factory()->create())->assignRole('user-manager'))->get('/admin')
            ->assertOk()
            ->assertDontSee('Your albums');
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
        Page::factory()->create(['body' => '<div data-type="customBlock" data-id="photo" data-config="'.e(json_encode(['photo_id' => $onPage->id])).'"></div>']);

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
        $moderator = tap(User::factory()->create())->assignRole(Role::Moderator->value);

        $this->actingAs($moderator);
        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertActionHidden(ForceDeleteAction::class)
            ->assertActionHidden(RestoreAction::class);

        $this->actingAs($owner);
        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertActionVisible(ForceDeleteAction::class)
            ->assertActionVisible(RestoreAction::class);
    });
});

describe('trash', function () {
    it('moves the albums inside an album to the trash with it and brings them back together', function () {
        actingAsRole();
        $camp = Album::factory()->create(['title' => 'Camp']);
        $day = Album::factory()->create(['title' => 'Day 3', 'parent_id' => $camp->id]);
        $evening = Album::factory()->create(['title' => 'Evening', 'parent_id' => $day->id]);
        $earlier = tap(Album::factory()->create(['title' => 'Rejects', 'parent_id' => $camp->id]))->delete();
        $this->travel(1)->minutes();
        $other = Album::factory()->create(['title' => 'Other']);

        $page = Livewire::test(EditAlbum::class, ['record' => $camp->getRouteKey()])
            ->mountAction(DeleteAction::class)
            ->assertActionMounted(DeleteAction::class);
        expect($page->instance()->getMountedAction()->getModalDescription())
            ->toBe('The 2 albums inside it go to the trash too, and come back when you restore this one. You can restore it from the trash.');
        $page->callMountedAction();

        expect(Album::onlyTrashed()->pluck('title')->sort()->values()->all())->toBe(['Camp', 'Day 3', 'Evening', 'Rejects'])
            ->and($other->fresh()->trashed())->toBeFalse();

        Livewire::test(EditAlbum::class, ['record' => $camp->getRouteKey()])->callAction(RestoreAction::class);

        expect(Album::onlyTrashed()->pluck('title')->all())->toBe(['Rejects'])
            ->and($evening->fresh()->trashed())->toBeFalse();
    });

    it('links the album edit page to the album on the site, except while it is in the trash', function () {
        actingAsRole();
        $album = Album::factory()->create();
        $trashed = tap(Album::factory()->create())->delete();

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->assertActionHasUrl('open', route('albums.show', $album))
            ->assertActionShouldOpenUrlInNewTab('open');
        Livewire::test(EditAlbum::class, ['record' => $trashed->getRouteKey()])
            ->assertActionHidden('open');
    });

    it('hides the photos of albums trashed with their parent on the site', function () {
        $camp = Album::factory()->visibility(Visibility::Public)->create();
        $day = Album::factory()->visibility(Visibility::Public)->create(['parent_id' => $camp->id]);

        $camp->delete();

        $this->get(route('albums.show', $day))->assertNotFound();
    });
});
