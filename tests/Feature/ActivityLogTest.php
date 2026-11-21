<?php

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use App\Filament\Resources\ActivityEvents\Pages\ManageActivityEvents;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Support\DashboardStats;
use Illuminate\Support\Carbon;

function staff(Role $role = Role::Admin): User
{
    return tap(User::factory()->create())->assignRole($role->value);
}

it('records a login', function () {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    expect(ActivityEvent::where('type', ActivityType::Login)->sole())
        ->user_id->toBe($user->id);
});

it('records guest album views anonymously, once per day and album', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create();

    $this->get(route('albums.show', $album));
    $this->get(route('albums.show', $album));

    $event = ActivityEvent::where('type', ActivityType::AlbumView)->sole();
    expect($event->user_id)->toBeNull()
        ->and($event->album_id)->toBe($album->id)
        ->and($event->visitor)->toHaveLength(64)
        ->and($event->visitor)->not->toContain('127.0.0.1');
});

it('records nothing while the activity log is turned off', function () {
    config(['gallery.activity_log_level' => 'off']);
    $album = Album::factory()->visibility(Visibility::Public)->create();

    $this->get(route('albums.show', $album));

    expect(ActivityEvent::where('type', ActivityType::AlbumView)->count())->toBe(0);
});

it('counts distinct members per day for the calendar', function () {
    $this->travelTo(Carbon::parse('2026-11-13 12:00'));
    [$anna, $ben] = User::factory()->count(2)->create();

    ActivityEvent::factory()->for($anna)->create(['created_at' => '2026-11-12 08:00']);
    ActivityEvent::factory()->for($anna)->create(['created_at' => '2026-11-12 20:00']);
    ActivityEvent::factory()->for($ben)->create(['created_at' => '2026-11-12 09:00']);
    ActivityEvent::factory()->for($ben)->create(['created_at' => '2026-11-10 09:00']);
    ActivityEvent::factory()->guestView()->create(['created_at' => '2026-11-12 10:00']);

    $calendar = DashboardStats::calendar();
    $days = collect($calendar['weeks'])->flatten(1)->keyBy(fn ($day) => $day['date']->toDateString());

    expect($days['2026-11-12']['count'])->toBe(2)
        ->and($days['2026-11-12']['level'])->toBe(4)
        ->and($days['2026-11-10']['level'])->toBe(2)
        ->and($days['2026-11-14']['future'])->toBeTrue()
        ->and($calendar['activeDays'])->toBe(2)
        ->and($calendar['people'])->toBe(2)
        ->and(DashboardStats::calendar(DashboardStats::GUESTS)['people'])->toBe(1);
});

it('ranks the most viewed albums of the last 30 days', function () {
    $this->freezeTime();
    [$popular, $quiet, $old] = Album::factory()->count(3)->create();

    ActivityEvent::factory()->count(3)->guestView()->for($popular)->create();
    ActivityEvent::factory()->guestView()->for($quiet)->create();
    ActivityEvent::factory()->count(5)->guestView()->for($old)->create(['created_at' => now()->subDays(31)]);

    expect(DashboardStats::popularAlbums(staff())->map(fn ($row) => [$row->album->id, $row->views])->all())
        ->toBe([[$popular->id, 3], [$quiet->id, 1]]);
});

it('prunes entries older than the retention period', function () {
    $this->freezeTime();
    config(['gallery.activity_retention_days' => 30]);
    $kept = ActivityEvent::factory()->create(['created_at' => now()->subDays(29)]);
    ActivityEvent::factory()->create(['created_at' => now()->subDays(31)]);

    $this->artisan('model:prune', ['--model' => [ActivityEvent::class]]);

    expect(ActivityEvent::where('type', ActivityType::Login)->pluck('id')->all())->toBe([$kept->id]);
});

it('shows library figures and activity on the dashboard', function () {
    $album = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp']);
    ActivityEvent::factory()->guestView()->for($album)->create();

    $this->actingAs(staff())
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['albums', 'photos', 'members', 'groups'])
        ->assertSee('A guest')
        ->assertSee('Summer camp')
        ->assertSee('og-calendar-grid', false);
});

it('escapes member names and album titles in the activity feed', function () {
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
    ActivityEvent::factory()->for($user)->create();

    $this->actingAs(staff())
        ->get('/admin')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
});

it('hides activity from staff without the view activity permission', function () {
    RoleModel::findOrCreate('helper')->givePermissionTo('access admin panel');
    $helper = tap(User::factory()->create())->assignRole('helper');
    ActivityEvent::factory()->guestView()->create();

    $this->actingAs($helper)->get('/admin')->assertOk()->assertDontSee('Recent activity')->assertDontSee('of photos stored');
    $this->actingAs($helper)->get(ActivityEventResource::getUrl())->assertForbidden();
    $this->actingAs(staff(Role::Moderator))->get(ActivityEventResource::getUrl())->assertOk()->assertSee('Guest');
});

it('filters the activity log by kind and by members or guests', function () {
    $member = User::factory()->create();
    $login = ActivityEvent::factory()->for($member)->create();
    $guest = ActivityEvent::factory()->guestView()->create();
    $this->actingAs(staff());

    Livewire\Livewire::test(ManageActivityEvents::class)
        ->filterTable('member', false)
        ->assertCanSeeTableRecords([$guest])
        ->assertCanNotSeeTableRecords([$login])
        ->resetTableFilters()
        ->filterTable('kind', 'visits')
        ->filterTable('member', true)
        ->assertCanSeeTableRecords([$login])
        ->assertCanNotSeeTableRecords([$guest]);
});
