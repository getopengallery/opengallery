<?php

use App\Enums\ActivityLevel;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Models\ActivityEvent;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Livewire\Livewire;

beforeEach(function () {
    $this->before = (int) ActivityEvent::max('id');
});

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin, ['name' => 'Ada']));

    it('saves the activity log level', function () {
        Livewire::test(ActivityLogSettings::class)
            ->fillForm(['activity_level' => ActivityLevel::ChangesAndLogins->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Setting::get(SiteSettings::ACTIVITY_LEVEL))->toBe('logins')
            ->and(SiteSettings::activityLevel())->toBe(ActivityLevel::ChangesAndLogins);
    });

    it('records turning the activity log off, before it stops recording', function () {
        Livewire::test(ActivityLogSettings::class)->fillForm(['activity_level' => 'off'])->call('save')->assertHasNoFormErrors();

        expect(newEntries()->where('type', ActivityType::SettingsChanged)->sole()->details())->toBe(['Activity log detail']);

        Livewire::test(ActivityLogSettings::class)->fillForm(['activity_level' => 'all'])->call('save');
        expect(newEntries()->where('type', ActivityType::SettingsChanged))->toHaveCount(2);
    });

    it('keeps activity entries for the chosen number of days', function () {
        $this->freezeTime();

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
        Livewire::test(ActivityLogSettings::class)
            ->fillForm(['activity_retention' => $days])
            ->call('save')
            ->assertHasFormErrors(['activity_retention']);
    })->with([0, 5000, '']);

    it('keeps the activity log highlighted in the menu on the log settings page', function () {
        $html = $this->get(ActivityLogSettings::getUrl())->assertOk()->getContent();

        preg_match('/<li[^>]*fi-sidebar-item[^>]*fi-active[^>]*>.*?<\/li>/s', $html, $activeItem);

        expect($activeItem[0] ?? '')->toContain('Activity log');
    });
});

it('lists the log settings in the menu for people who may change settings but not open the log', function () {
    $this->actingAs(tap(User::factory()->create())->givePermissionTo(Permission::AccessAdmin->value, Permission::ManageSettings->value));

    expect(adminMenu()['System'])->toBe(['Log settings', 'Background jobs', 'Updates']);
});
