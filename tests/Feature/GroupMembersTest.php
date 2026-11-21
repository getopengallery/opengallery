<?php

use App\Enums\Role;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Filament\Tables\Columns\UserStackColumn;
use App\Models\ActivityEvent;
use App\Models\Group;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value)));

it('shows the first members with their own name and counts the rest', function () {
    $group = Group::factory()->create();
    $group->users()->attach(User::factory()->count(UserStackColumn::SHOWN + 2)
        ->sequence(fn ($sequence) => ['name' => 'Member '.chr(65 + $sequence->index)])
        ->create());

    $html = Livewire::test(ListGroups::class)->html();

    expect(substr_count($html, 'alt="Member '))->toBe(UserStackColumn::SHOWN)
        ->and($html)->toContain('alt="Member A"', '+2', '2 more')
        ->not->toContain('alt="Member H"')
        // Each picture's tooltip names only that member.
        ->and($html)->toContain("x-tooltip=\"{ content: 'Member A'");
});

it('loads only a few members per group, however large it is', function () {
    $group = Group::factory()->create();
    $group->users()->attach(User::factory()->count(30)->create());

    $page = Livewire::test(ListGroups::class);
    $record = $page->instance()->getTableRecords()->first();

    expect($record->users)->toHaveCount(UserStackColumn::SHOWN)
        ->and($record->users_count)->toBe(30);
});

it('escapes member names in the list', function () {
    $group = Group::factory()->create();
    $group->users()->attach(User::factory()->create(['name' => '<img src=x onerror=alert(1)>']));

    Livewire::test(ListGroups::class)->assertDontSeeHtml('<img src=x onerror');
});

it('adds and removes members on the group page, and logs it', function () {
    $group = Group::factory()->create(['name' => 'Choir']);
    [$anna, $ben] = User::factory()->count(2)->sequence(['name' => 'Anna'], ['name' => 'Ben'])->create();

    $members = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditGroup::class])
        ->callAction(TestAction::make('attach')->table(), ['recordId' => [$anna->id, $ben->id]])
        ->assertHasNoActionErrors();
    expect($group->users()->pluck('name')->sort()->values()->all())->toBe(['Anna', 'Ben'])
        ->and(ActivityEvent::query()->where('subject_type', Group::class)->where('subject_id', $group->id)->latest('id')->first()->details())
        ->toContain('Members: +Anna, +Ben');

    $members->callAction(TestAction::make('detach')->table($ben));
    expect($group->users()->pluck('name')->all())->toBe(['Anna']);
});

it('opens the group page after creating a group', function () {
    Livewire::test(CreateGroup::class)
        ->fillForm(['name' => 'Neighbours'])
        ->call('create')
        ->assertRedirect(GroupResource::getUrl('edit', ['record' => Group::where('name', 'Neighbours')->sole()]));
});
