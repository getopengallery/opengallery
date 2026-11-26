<?php

use App\Enums\ActivityLevel;
use App\Enums\ActivityType;
use App\Enums\PhotoOrder;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Filament\Clusters\Settings\Pages\PhotoSettings;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Auth\Events\Login;
use Livewire\Livewire;

it('saves the default photo order and uses it for new albums only', function () {
    actingAsRole();
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
    $admin = actingAsRole();
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
    actingAsRole();

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
    actingAsRole();

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
    actingAsRole();

    Livewire::test(ActivityLogSettings::class)
        ->fillForm(['activity_retention' => $days])
        ->call('save')
        ->assertHasFormErrors(['activity_retention']);
})->with([0, 5000, '']);

describe('lightbox and download settings', function () {
    it('starts with downloads off and favorites, information and the slideshow on', function () {
        expect(SiteSettings::downloadsEnabled())->toBeFalse()
            ->and(SiteSettings::favoritesEnabled())->toBeTrue()
            ->and(SiteSettings::photoInfoEnabled())->toBeTrue()
            ->and(SiteSettings::slideshowEnabled())->toBeTrue()
            ->and(SiteSettings::slideshowSeconds())->toBe(5);
    });

    it('saves them on the photo settings page', function () {
        actingAsRole();

        Livewire::test(PhotoSettings::class)
            ->assertFormSet(['downloads_enabled' => false, 'favorites_enabled' => true, 'slideshow_seconds' => 5])
            ->fillForm([
                'downloads_enabled' => true,
                'favorites_enabled' => false,
                'photo_info_enabled' => false,
                'slideshow_enabled' => true,
                'slideshow_seconds' => 12,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(SiteSettings::downloadsEnabled())->toBeTrue()
            ->and(SiteSettings::favoritesEnabled())->toBeFalse()
            ->and(SiteSettings::photoInfoEnabled())->toBeFalse()
            ->and(SiteSettings::slideshowEnabled())->toBeTrue()
            ->and(SiteSettings::slideshowSeconds())->toBe(12);
    });

    it('rejects slideshow seconds outside the allowed range', function (int $seconds) {
        actingAsRole();

        Livewire::test(PhotoSettings::class)
            ->fillForm(['slideshow_enabled' => true, 'slideshow_seconds' => $seconds])
            ->call('save')
            ->assertHasFormErrors(['slideshow_seconds']);

        expect(SiteSettings::slideshowSeconds())->toBe(5);
    })->with([
        'below the minimum' => SiteSettings::SLIDESHOW_SECONDS_MIN - 1,
        'above the maximum' => SiteSettings::SLIDESHOW_SECONDS_MAX + 1,
    ]);

    it('accepts the limits themselves', function (int $seconds) {
        actingAsRole();

        Livewire::test(PhotoSettings::class)
            ->fillForm(['slideshow_seconds' => $seconds])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(SiteSettings::slideshowSeconds())->toBe($seconds);
    })->with([SiteSettings::SLIDESHOW_SECONDS_MIN, SiteSettings::SLIDESHOW_SECONDS_MAX]);

    it('keeps a stored value inside the range', function (mixed $stored, int $expected) {
        Setting::put(SiteSettings::SLIDESHOW_SECONDS, $stored);

        expect(SiteSettings::slideshowSeconds())->toBe($expected);
    })->with([[1, 2], [500, 60], [0, 5]]);

    it('shows the seconds only with the slideshow on and the location option only with downloads on', function () {
        actingAsRole();

        Livewire::test(PhotoSettings::class)
            ->fillForm(['slideshow_enabled' => false, 'downloads_enabled' => false])
            ->assertFormFieldHidden('slideshow_seconds')
            ->assertFormFieldHidden('remove_location')
            ->fillForm(['slideshow_enabled' => true, 'downloads_enabled' => true])
            ->assertFormFieldVisible('slideshow_seconds')
            ->assertFormFieldVisible('remove_location');
    });
});
