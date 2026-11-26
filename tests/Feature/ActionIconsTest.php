<?php

use App\Enums\Role;
use App\Filament\Actions\ActionIcons;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Photos\Pages\ListPhotos;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Models\Group;
use App\Models\Page;
use App\Models\Photo;
use App\Models\Role as RoleModel;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::SuperAdmin->value));
});

it('gives Filament\'s standard actions one icon and colour per kind', function (string $action, $icon, ?string $color) {
    $made = $action::make();

    expect($made->getIcon())->toBe($icon);

    if ($color !== null) {
        expect($made->getColor())->toBe($color);
    }
})->with([
    'edit' => [EditAction::class, ActionIcons::EDIT, null],
    'view' => [ViewAction::class, ActionIcons::VIEW, null],
    'delete' => [DeleteAction::class, ActionIcons::DELETE, 'danger'],
    'delete bulk' => [DeleteBulkAction::class, ActionIcons::DELETE, 'danger'],
    'force delete' => [ForceDeleteAction::class, ActionIcons::DELETE, 'danger'],
    'force delete bulk' => [ForceDeleteBulkAction::class, ActionIcons::DELETE, 'danger'],
    'restore' => [RestoreAction::class, ActionIcons::RESTORE, 'gray'],
    'restore bulk' => [RestoreBulkAction::class, ActionIcons::RESTORE, 'gray'],
    'detach' => [DetachAction::class, ActionIcons::REMOVE, 'gray'],
    'detach bulk' => [DetachBulkAction::class, ActionIcons::REMOVE, 'gray'],
]);

it('lets an icon set on the action itself win over the default', function () {
    expect(DeleteAction::make()->icon(ActionIcons::VIEW)->getIcon())->toBe(ActionIcons::VIEW);
});

it('uses the same icons in the trash actions, restore being gray', function () {
    expect(TrashActions::moveToTrash()->getIcon())->toBe(ActionIcons::DELETE)
        ->and(TrashActions::moveToTrashBulk()->getIcon())->toBe(ActionIcons::DELETE)
        ->and(TrashActions::deletePermanently()->getIcon())->toBe(ActionIcons::DELETE)
        ->and(TrashActions::deletePermanentlyBulk()->getIcon())->toBe(ActionIcons::DELETE)
        ->and(TrashActions::restore()->getIcon())->toBe(ActionIcons::RESTORE)
        ->and(TrashActions::restore()->getColor())->toBe('gray')
        ->and(TrashActions::restoreBulk()->getIcon())->toBe(ActionIcons::RESTORE)
        ->and(TrashActions::restoreBulk()->getColor())->toBe('gray');
});

it('gives a custom remove action the remove icon and gray', function () {
    $action = ActionIcons::remove(Action::make('remove'));

    expect($action->getIcon())->toBe(ActionIcons::REMOVE)
        ->and($action->getColor())->toBe('gray');
});

it('opens the edit page from the row instead of an edit button', function (string $list, string $resource, Closure $record) {
    $record = $record();
    $component = Livewire::test($list);

    $component->assertActionDoesNotExist(TestAction::make('edit')->table($record));
    expect($component->instance()->getTable()->getRecordUrl($record))->toBe($resource::getUrl('edit', ['record' => $record]));
})->with([
    'users' => [ListUsers::class, UserResource::class, fn () => User::factory()->create()],
    'pages' => [ListPages::class, PageResource::class, fn () => Page::factory()->create()],
    'groups' => [ListGroups::class, GroupResource::class, fn () => Group::factory()->create()],
    'roles' => [ListRoles::class, RoleResource::class, fn () => RoleModel::findOrCreate('curators')],
    'albums' => [ListAlbums::class, AlbumResource::class, fn () => Album::factory()->create()],
]);

it('shows the delete button with an icon on record page headers', function (string $page, Closure $record) {
    Livewire::test($page, ['record' => $record()->getRouteKey()])
        ->assertActionExists('delete')
        ->assertActionHasIcon('delete', ActionIcons::DELETE)
        ->assertActionHasColor('delete', 'danger');
})->with([
    'page' => [EditPage::class, fn () => Page::factory()->create()],
    'group' => [EditGroup::class, fn () => Group::factory()->create()],
    'role' => [EditRole::class, fn () => RoleModel::findOrCreate('curators')],
]);

it('shows the restore and delete permanently buttons with icons on a trashed album', function () {
    $album = Album::factory()->create();
    $album->delete();

    Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
        ->assertActionHasIcon('restore', ActionIcons::RESTORE)
        ->assertActionHasColor('restore', 'gray')
        ->assertActionHasIcon('forceDelete', ActionIcons::DELETE)
        ->assertActionHasColor('forceDelete', 'danger');
});

it('shows "Remove from album" in the media list as a gray remove action', function () {
    $photo = Photo::factory()->inAlbum(Album::factory()->create())->create();

    Livewire::test(ListPhotos::class)
        ->call('setLayout', ListPhotos::LIST)
        ->assertActionExists(TestAction::make('removeFromAlbum')->table()->bulk())
        ->assertActionHasIcon(TestAction::make('removeFromAlbum')->table()->bulk(), ActionIcons::REMOVE)
        ->assertActionHasColor(TestAction::make('removeFromAlbum')->table()->bulk(), 'gray');
});

it('shows the bulk approve action in green with the approve icon', function () {
    $bulk = TestAction::make('approve')->table()->bulk();

    Livewire::test(ListUsers::class)
        ->assertActionHasIcon($bulk, ActionIcons::APPROVE)
        ->assertActionHasColor($bulk, 'success');
});
