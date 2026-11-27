<?php

use App\Enums\CollaboratorRole;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Actions\ActionIcons;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Tables\Columns\UserStackColumn;
use App\Filament\Tables\FilterBar;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\User;
use App\Notifications\GroupAlbumsShared;
use App\Support\AlbumCollaborators;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin));

    it('lists the first members of each group, linked and escaped, and counts the rest', function () {
        $choir = Group::factory()->create();
        $choir->users()->attach(User::factory()->count(UserStackColumn::SHOWN + 2)
            ->sequence(fn ($sequence) => ['name' => 'Member '.chr(65 + $sequence->index)])
            ->create());
        $orchestra = Group::factory()->create();
        $orchestra->users()->attach(User::factory()->count(30)->create());
        $band = Group::factory()->create();
        $band->users()->attach($hacker = User::factory()->create(['name' => '<img src=x onerror=alert(1)>']));

        $page = Livewire::test(ListGroups::class);
        $html = $page->html();
        $table = $page->instance()->getTable();
        $records = $page->instance()->getTableRecords();

        expect(substr_count($html, 'alt="Member '))->toBe(UserStackColumn::SHOWN)
            ->and($html)->toContain('alt="Member A"', '+2', '2 more')
            ->not->toContain('alt="Member H"')
            // Each picture's tooltip names only that member.
            ->and($html)->toContain("x-tooltip=\"{ content: 'Member A'")
            // Only a few members are loaded per group, however large it is.
            ->and($records->firstWhere('id', $orchestra->id)->users)->toHaveCount(UserStackColumn::SHOWN)
            ->and($records->firstWhere('id', $orchestra->id)->users_count)->toBe(30)
            // Member names are escaped, and link to the person.
            ->and($html)->not->toContain('<img src=x onerror')
            ->and($html)->toContain(UserResource::getUrl('edit', ['record' => $hacker]))
            // The list filters with chips that apply at once, and opens the group from the row.
            ->and($table->getFiltersLayout())->toBe(FiltersLayout::Hidden)
            ->and(FilterBar::uses($table))->toBeTrue()
            ->and($table->hasDeferredFilters())->toBeFalse()
            ->and($table->getRecordUrl($band))->toBe(GroupResource::getUrl('edit', ['record' => $band]));

        $page->assertActionDoesNotExist(TestAction::make('edit')->table($band))
            ->mountAction(TestAction::make('delete')->table($band));

        // Deleting a group has no trash to fall back on.
        expect($page->instance()->getMountedAction()->getModalDescription())->toBe('This cannot be undone.');
    });

    it('filters groups by member', function () {
        $member = User::factory()->create();
        [$joined, $other] = Group::factory()->count(2)->create();
        $joined->users()->attach($member);

        Livewire::test(ListGroups::class)
            ->filterTable('users', $member->id)
            ->assertCanSeeTableRecords([$joined])
            ->assertCanNotSeeTableRecords([$other]);
    });

    it('says what an empty list is for and offers to fill it', function () {
        Livewire::test(ListGroups::class)
            ->assertSee('No groups yet')
            ->assertSee('Groups let you share restricted albums with several people at once.')
            ->assertActionVisible(TestAction::make('create')->table());
    });

    it('says nothing matches instead while a search hides every row', function () {
        Group::factory()->create(['name' => 'Family']);

        Livewire::test(ListGroups::class)
            ->searchTable('Nobody here')
            ->assertSee('Nothing matches')
            ->assertDontSee('No groups yet')
            ->assertActionHidden(TestAction::make('create')->table());
    });

    it('opens the group page after creating a group', function () {
        Livewire::test(CreateGroup::class)
            ->fillForm(['name' => 'Neighbours'])
            ->call('create')
            ->assertRedirect(GroupResource::getUrl('edit', ['record' => Group::where('name', 'Neighbours')->sole()]));
    });

    it('shows the delete button with an icon on the group page', function () {
        Livewire::test(EditGroup::class, ['record' => Group::factory()->create()->getRouteKey()])
            ->assertActionExists('delete')
            ->assertActionHasIcon('delete', ActionIcons::DELETE)
            ->assertActionHasColor('delete', 'danger');
    });

    it('adds and removes members on the group page, and logs it', function () {
        $group = Group::factory()->create(['name' => 'Choir']);
        [$anna, $ben] = User::factory()->count(2)->sequence(['name' => 'Anna'], ['name' => 'Ben'])->create();

        $members = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditGroup::class])
            ->callAction(TestAction::make('attach')->table(), ['recordId' => [$anna->id, $ben->id]])
            ->assertHasNoActionErrors()
            ->assertSeeHtml(UserResource::getUrl('edit', ['record' => $anna]));
        expect($group->users()->pluck('name')->sort()->values()->all())->toBe(['Anna', 'Ben'])
            ->and(ActivityEvent::query()->where('subject_type', Group::class)->where('subject_id', $group->id)->latest('id')->first()->details())
            ->toContain('Members: +Anna, +Ben');

        $members->callAction(TestAction::make('detach')->table($ben));
        expect($group->users()->pluck('name')->all())->toBe(['Anna']);
    });
});

describe('telling new members about the albums their group gives them', function () {
    beforeEach(fn () => Notification::fake());

    it('tells new members once, however many albums the group gives them', function () {
        [, $group] = groupWithAlbum();
        Album::factory()->visibility(Visibility::Restricted)->create()->sharedGroups()->attach($group);
        $grace = User::factory()->create();

        addMembers($group, [$grace]);

        Notification::assertSentToTimes($grace, GroupAlbumsShared::class, 1);
        Notification::assertSentTo($grace, GroupAlbumsShared::class, fn (GroupAlbumsShared $notice) => $notice->albums === 2 && $notice->addedBy === 'Ada');
    });

    it('counts albums the group may work on, but not those the person already has', function () {
        [$ada, $group, $album] = groupWithAlbum();
        $grace = User::factory()->create();
        $album->sharedUsers()->attach($grace);
        $worked = Album::factory()->visibility(Visibility::Private)->create();
        AlbumCollaborators::add($worked, ['group:'.$group->id], CollaboratorRole::cases()[0], $ada);

        addMembers($group, [$grace]);

        Notification::assertSentTo($grace, GroupAlbumsShared::class, fn (GroupAlbumsShared $notice) => $notice->albums === 1);
    });

    it('says nothing when the group gives them nothing new', function () {
        [, $group, $album] = groupWithAlbum();
        $album->update(['visibility' => Visibility::Public]);
        $grace = User::factory()->create();

        addMembers($group, [$grace]);

        Notification::assertNotSentTo($grace, GroupAlbumsShared::class);
    });

    it('stays quiet when the switch is off', function () {
        [, $group] = groupWithAlbum();
        $grace = User::factory()->create();

        addMembers($group, [$grace], tell: false);

        Notification::assertNotSentTo($grace, GroupAlbumsShared::class);
        expect($group->users()->whereKey($grace->id)->exists())->toBeTrue();
    });
});
