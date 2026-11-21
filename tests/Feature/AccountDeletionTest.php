<?php

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Text;
use Livewire\Livewire;

function adminUser(): User
{
    return tap(User::factory()->create())->assignRole(Role::Admin->value);
}

it('keeps a user\'s albums when the user row is deleted directly', function () {
    $album = Album::factory()->create();

    $album->owner->delete();

    expect($album->fresh())->not->toBeNull()
        ->and($album->fresh()->owner_id)->toBeNull();
});

describe('in the admin', function () {
    it('lists the albums and gives them to the person deleting by default', function () {
        $admin = adminUser();
        $this->actingAs($admin);
        $user = User::factory()->create();
        $album = Album::factory()->for($user, 'owner')->create(['title' => 'Summer camp']);
        $trashed = tap(Album::factory()->for($user, 'owner')->create())->delete();
        $unsorted = Photo::factory()->inAlbum(Album::inbox($user))->create(['uploader_id' => $user->id]);
        $onPage = Photo::factory()->inAlbum(Album::pageImages($user))->create(['uploader_id' => $user->id]);

        $page = Livewire::test(EditUser::class, ['record' => $user->id])
            ->mountAction(DeleteAction::class)
            ->assertSchemaStateSet(['new_owner_id' => $admin->id], 'mountedActionSchema0');

        $notice = collect($page->instance()->getSchema('mountedActionSchema0')->getFlatComponents())
            ->first(fn ($component) => $component instanceof Text)
            ->getContent();
        expect($notice)->toStartWith('Their albums (3):')->toContain('Summer camp', 'Unsorted uploads')->not->toContain('Page images');

        $page->callMountedAction()->assertHasNoFormErrors();

        expect($user->fresh())->toBeNull()
            ->and($album->fresh()->owner_id)->toBe($admin->id)
            ->and($album->fresh()->trashed())->toBeFalse()
            ->and($trashed->fresh()->owner_id)->toBe($admin->id)
            // Their system albums are merged into the new owner's own.
            ->and($unsorted->fresh()->albums->modelKeys())->toBe([Album::inbox($admin)->id])
            ->and($onPage->fresh()->albums->modelKeys())->toBe([Album::pageImages($admin)->id])
            ->and(Album::withTrashed()->where('owner_id', $admin->id)->whereNotNull('system_album')->count())->toBe(2);
    });

    it('gives the albums to another chosen person', function () {
        $this->actingAs(adminUser());
        $user = User::factory()->create();
        $heir = User::factory()->create();
        $album = Album::factory()->for($user, 'owner')->create();

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->callAction(DeleteAction::class, ['new_owner_id' => $heir->id])
            ->assertHasNoFormErrors();

        expect($album->fresh()->owner_id)->toBe($heir->id);
    });

    it('deletes a person from their row in the users list, asking who gets their albums', function () {
        $this->actingAs(adminUser());
        $user = User::factory()->create();
        $heir = User::factory()->create();
        $album = Album::factory()->for($user, 'owner')->create();

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('delete')->table($user), ['new_owner_id' => $heir->id])
            ->assertHasNoFormErrors();

        expect($user->fresh())->toBeNull()
            ->and($album->fresh()->owner_id)->toBe($heir->id);
    });

    it('moves the albums to the trash without a new owner, but keeps "Page images"', function () {
        $this->actingAs(adminUser());
        $user = User::factory()->create();
        $album = Album::factory()->for($user, 'owner')->create();
        $inbox = Album::inbox($user);
        $pageImages = Album::pageImages($user);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->callAction(DeleteAction::class, ['new_owner_id' => null])
            ->assertHasNoFormErrors();

        $album = Album::withTrashed()->find($album->id);

        expect($user->fresh())->toBeNull()
            ->and($album->trashed())->toBeTrue()
            ->and($album->owner_id)->toBeNull()
            ->and(Album::withTrashed()->find($inbox->id)->trashed())->toBeTrue()
            ->and($pageImages->fresh()->trashed())->toBeFalse()
            ->and($pageImages->fresh()->owner_id)->toBeNull();
    });

    it('refuses someone who is being deleted as the new owner', function () {
        $this->actingAs(adminUser());
        $first = User::factory()->create();
        $second = User::factory()->create();
        Album::factory()->for($first, 'owner')->create();

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk(), ['new_owner_id' => $second->id])
            ->assertHasFormErrors(['new_owner_id']);

        expect($first->fresh())->not->toBeNull()
            ->and($second->fresh())->not->toBeNull();
    });

    it('gives the albums of everyone deleted in bulk to the new owner', function () {
        $admin = adminUser();
        $this->actingAs($admin);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $albums = collect([$first, $second])->map(fn (User $user) => Album::factory()->for($user, 'owner')->create());

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            ->assertHasNoFormErrors();

        expect(User::whereKey([$first->id, $second->id])->exists())->toBeFalse()
            ->and($albums->map(fn (Album $album) => $album->fresh()->owner_id)->unique()->all())->toBe([$admin->id]);
    });
});

describe('in the profile', function () {
    it('lists the person\'s albums in the deletion dialog', function () {
        $user = User::factory()->create();
        Album::factory()->for($user, 'owner')->create(['title' => 'Summer camp']);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Summer camp')
            ->assertSee('Delete my albums and photos with my account');
    });

    it('refuses to delete an account that owns albums without confirmation', function () {
        $user = User::factory()->create();
        $album = Album::factory()->for($user, 'owner')->create();

        $this->actingAs($user)->from('/profile')
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', ['delete_albums' => 'Your albums would be deleted too. Confirm this, or cancel and keep your account.'])
            ->assertRedirect('/profile');

        expect($user->fresh())->not->toBeNull()
            ->and($album->fresh())->not->toBeNull();
    });

    it('deletes the account with its albums and the photos left in no album once confirmed', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $album = Album::factory()->for($user, 'owner')->create();
        $othersAlbum = Album::factory()->for($other, 'owner')->create();
        $othersSubAlbum = Album::factory()->for($other, 'owner')->create(['parent_id' => $album->id]);
        $onlyHere = Photo::factory()->inAlbum($album)->create(['uploader_id' => $user->id]);
        $sharedPhoto = Photo::factory()->inAlbum($album)->inAlbum($othersAlbum)->create(['uploader_id' => $user->id]);
        $unsortedOwn = Photo::factory()->inAlbum(Album::inbox($user))->create(['uploader_id' => $user->id]);
        $pageImages = Album::pageImages($user);
        $onPage = Photo::factory()->inAlbum($pageImages)->create(['uploader_id' => $user->id]);

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password', 'delete_albums' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $othersSubAlbum->refresh();

        expect($user->fresh())->toBeNull()
            ->and(Album::withTrashed()->find($album->id))->toBeNull()
            ->and(Photo::withTrashed()->find($onlyHere->id))->toBeNull()
            ->and(Photo::withTrashed()->find($unsortedOwn->id))->toBeNull()
            ->and($sharedPhoto->fresh())->not->toBeNull()
            ->and(Album::withTrashed()->where('owner_id', $user->id)->exists())->toBeFalse()
            ->and($pageImages->fresh()->owner_id)->toBeNull()
            ->and($onPage->fresh())->not->toBeNull()
            ->and($othersSubAlbum->parent_id)->toBeNull()
            ->and($othersSubAlbum->depth())->toBe(0);
    });
});
