<?php

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use App\Filament\Resources\ActivityEvents\Pages\ManageActivityEvents;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Tables\FilterBar;
use App\Models\ActivityEvent;
use App\Models\Role as RoleModel;
use App\Models\User;
use Filament\Tables\Enums\FiltersLayout;
use Livewire\Livewire;

beforeEach(function () {
    $this->before = (int) ActivityEvent::max('id');
});

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin));

    it('lists the log in a dropdown filter, linking people but keeping the account behind a failed login unlinked', function () {
        $owner = User::factory()->create();
        $login = ActivityEvent::factory()->create(['user_id' => $owner->id]);
        $target = User::factory()->create();
        $failed = ActivityEvent::factory()->create(['type' => ActivityType::LoginFailed, 'user_id' => $target->id]);

        $page = Livewire::test(ManageActivityEvents::class);
        $table = $page->instance()->getTable();
        $columns = $table->getColumns();

        $page->assertCanSeeTableRecords([$login])->assertSeeHtml(UserResource::getUrl('edit', ['record' => $owner]));
        expect($table->getFiltersLayout())->toBe(FiltersLayout::Hidden)
            ->and(FilterBar::uses($table))->toBeTrue()
            ->and($table->hasDeferredFilters())->toBeFalse()
            // The log has no badges: its cells hold a single entry.
            ->and($columns['user.name']->isBadge())->toBeFalse()
            ->and($columns['what']->isBadge())->toBeFalse()
            ->and($columns['details']->isBadge())->toBeFalse();

        $page
            // Only the failed login: creating the account is logged too, and links it as the subject.
            ->filterTable('type', [ActivityType::LoginFailed->value])
            ->assertCanSeeTableRecords([$failed])
            ->assertDontSeeHtml(UserResource::getUrl('edit', ['record' => $target]));
    });

    it('filters the activity log by kind and by members or guests', function () {
        $member = User::factory()->create();
        $login = ActivityEvent::factory()->for($member)->create();
        $guest = ActivityEvent::factory()->guestView()->create();

        Livewire::test(ManageActivityEvents::class)
            ->filterTable('member', false)
            ->assertCanSeeTableRecords([$guest])
            ->assertCanNotSeeTableRecords([$login])
            ->resetTableFilters()
            ->filterTable('kind', 'visits')
            ->filterTable('member', true)
            ->assertCanSeeTableRecords([$login])
            ->assertCanNotSeeTableRecords([$guest]);
    });
});

it('records security events at the "changes only" level and shows them under their own kind', function () {
    config(['gallery.activity_log_level' => 'changes']);
    User::factory()->create(['email' => 'anna@example.com']);

    $this->post(route('login'), ['email' => 'anna@example.com', 'password' => 'wrong']);
    $failed = newEntries()->where('type', ActivityType::LoginFailed)->sole();

    actingAsRole(Role::Admin);

    Livewire::test(ManageActivityEvents::class)
        ->filterTable('kind', 'security')
        ->assertCanSeeTableRecords([$failed]);
});

it('hides the activity log from staff without the view activity permission', function () {
    RoleModel::findOrCreate('helper')->givePermissionTo('access admin panel');
    $helper = tap(User::factory()->create())->assignRole('helper');
    ActivityEvent::factory()->guestView()->create();

    $this->actingAs($helper)->get(ActivityEventResource::getUrl())->assertForbidden();
    $this->actingAs(userWithRole(Role::Moderator))->get(ActivityEventResource::getUrl())->assertOk()->assertSee('Guest');
});

it('offers the log settings on the activity log only to people who may change settings', function (array $permissions, bool $offered) {
    $this->actingAs(tap(User::factory()->create())->givePermissionTo(...array_map(fn (Permission $permission) => $permission->value, $permissions)));

    $page = Livewire::test(ManageActivityEvents::class);

    $offered ? $page->assertActionVisible('logSettings') : $page->assertActionHidden('logSettings');
})->with([
    'who may change settings' => [[Permission::AccessAdmin, Permission::ViewActivity, Permission::ManageSettings], true],
    'who may only read the log' => [[Permission::AccessAdmin, Permission::ViewActivity], false],
]);
