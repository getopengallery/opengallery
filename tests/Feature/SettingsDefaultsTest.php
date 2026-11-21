<?php

use App\Enums\ActivityLevel;
use App\Enums\ActivityType;
use App\Enums\PhotoOrder;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Filament\Clusters\Settings\Pages\PhotoSettings;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Auth\Events\Login;
use Livewire\Livewire;

function settingsAdmin(): User
{
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    test()->actingAs($admin);

    return $admin;
}

it('saves the default photo order and uses it for new albums only', function () {
    settingsAdmin();
    $existing = Album::factory()->create();

    Livewire::test(PhotoSettings::class)
        ->fillForm(['default_photo_order' => PhotoOrder::TakenDesc->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::defaultPhotoOrder())->toBe(PhotoOrder::TakenDesc)
        ->and(Album::factory()->create()->photo_order)->toBe(PhotoOrder::TakenDesc)
        ->and($existing->fresh()->photo_order)->toBe(PhotoOrder::Manual);

    Livewire::test(CreateAlbum::class)->assertSchemaStateSet(['photo_order' => PhotoOrder::TakenDesc]);
});

it('keeps an order chosen explicitly for an album', function () {
    config(['gallery.default_photo_order' => PhotoOrder::TitleAsc->value]);

    expect(Album::factory()->create(['photo_order' => PhotoOrder::UploadedDesc])->photo_order)->toBe(PhotoOrder::UploadedDesc);
});

it('records only what the chosen detail level includes', function (ActivityLevel $level, bool $changes, bool $logins, bool $views) {
    $admin = settingsAdmin();
    config(['gallery.activity_log_level' => $level->value]);
    $before = (int) ActivityEvent::max('id');
    $album = Album::factory()->visibility(Visibility::Public)->create();

    $album->update(['title' => 'Renamed']);
    event(new Login('web', $admin, false));
    $this->get(route('albums.show', $album));

    $recorded = fn (array $types) => ActivityEvent::where('id', '>', $before)->whereIn('type', $types)->exists();

    expect($recorded([ActivityType::Created, ActivityType::Updated]))->toBe($changes)
        ->and($recorded([ActivityType::Login]))->toBe($logins)
        ->and($recorded([ActivityType::AlbumView]))->toBe($views);
})->with([
    'off' => [ActivityLevel::Off, false, false, false],
    'changes only' => [ActivityLevel::Changes, true, false, false],
    'changes and logins' => [ActivityLevel::ChangesAndLogins, true, true, false],
    'everything' => [ActivityLevel::All, true, true, true],
]);

it('saves the activity log level in Settings', function () {
    settingsAdmin();

    Livewire::test(ActivityLogSettings::class)
        ->fillForm(['activity_level' => ActivityLevel::ChangesAndLogins->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get(SiteSettings::ACTIVITY_LEVEL))->toBe('logins')
        ->and(SiteSettings::activityLevel())->toBe(ActivityLevel::ChangesAndLogins);
});

it('keeps the choice of installs that only had the old on/off switch', function () {
    Setting::put(SiteSettings::ACTIVITY_LOG, false);
    SiteSettings::apply();
    expect(SiteSettings::activityLevel())->toBe(ActivityLevel::Off);

    Setting::put(SiteSettings::ACTIVITY_LOG, true);
    SiteSettings::apply();
    expect(SiteSettings::activityLevel())->toBe(ActivityLevel::All);
});

it('keeps activity entries for the chosen number of days', function () {
    settingsAdmin();

    Livewire::test(ActivityLogSettings::class)
        ->fillForm(['activity_retention' => 30])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::activityRetentionDays())->toBe(30);

    $old = ActivityEvent::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = ActivityEvent::factory()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [ActivityEvent::class]])->assertSuccessful();

    expect(ActivityEvent::find($old->id))->toBeNull()
        ->and(ActivityEvent::find($recent->id))->not->toBeNull();
});

it('rejects a retention period outside the allowed range', function (mixed $days) {
    settingsAdmin();

    Livewire::test(ActivityLogSettings::class)
        ->fillForm(['activity_retention' => $days])
        ->call('save')
        ->assertHasFormErrors(['activity_retention']);
})->with([0, 5000, '']);
