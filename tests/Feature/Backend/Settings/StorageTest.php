<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\StorageSettings;
use App\Support\SiteSettings;
use Livewire\Livewire;

beforeEach(fn () => actingAsRole(Role::Admin));

it('starts with limits off and a default of 10 GB', function () {
    Livewire::test(StorageSettings::class)
        ->assertFormSet(['storage_limits_enabled' => false, 'default_storage_quota_gb' => 10])
        ->assertDontSee('Default limit');

    expect(SiteSettings::storageLimitsEnabled())->toBeFalse()
        ->and(SiteSettings::defaultStorageQuotaGb())->toBe(10);
});

it('saves the switch and the default limit', function () {
    Livewire::test(StorageSettings::class)
        ->fillForm(['storage_limits_enabled' => true, 'default_storage_quota_gb' => 25])
        ->assertSee('Default limit')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::storageLimitsEnabled())->toBeTrue()
        ->and(SiteSettings::defaultStorageQuotaGb())->toBe(25);

    Livewire::test(StorageSettings::class)
        ->assertFormSet(['storage_limits_enabled' => true, 'default_storage_quota_gb' => 25]);
});

it('rejects a missing or out of range default limit while limits are on', function (mixed $value, string $rule) {
    Livewire::test(StorageSettings::class)
        ->fillForm(['storage_limits_enabled' => true, 'default_storage_quota_gb' => $value])
        ->call('save')
        ->assertHasFormErrors(['default_storage_quota_gb' => $rule]);

    expect(SiteSettings::storageLimitsEnabled())->toBeFalse();
})->with([
    'empty' => [null, 'required'],
    'zero' => [0, 'min'],
    'too large' => [SiteSettings::STORAGE_QUOTA_MAX_GB + 1, 'max'],
    'not a whole number' => ['1.5', 'integer'],
    'text' => ['lots', 'integer'],
]);

it('keeps the stored default while limits are turned off', function () {
    saveSettingsPage(['storage_limits_enabled' => true, 'default_storage_quota_gb' => 40], StorageSettings::class);

    saveSettingsPage(['storage_limits_enabled' => false], StorageSettings::class)->assertHasNoFormErrors();

    expect(SiteSettings::storageLimitsEnabled())->toBeFalse()
        ->and(SiteSettings::defaultStorageQuotaGb())->toBe(40);
});

it('keeps the default limit within its allowed range however it was stored', function (int $stored, int $expected) {
    applySiteSettings([SiteSettings::DEFAULT_STORAGE_QUOTA_GB => $stored]);

    expect(SiteSettings::defaultStorageQuotaGb())->toBe($expected);
})->with([
    'below the minimum' => [0, SiteSettings::STORAGE_QUOTA_MIN_GB],
    'above the maximum' => [5_000_000, SiteSettings::STORAGE_QUOTA_MAX_GB],
    'inside the range' => [250, 250],
]);
