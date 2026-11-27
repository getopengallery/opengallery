<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\LicenseSettings;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Models\License;
use App\Support\SiteSettings;
use Livewire\Livewire;

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin));

    it('saves the licensing settings and links to the license list', function () {
        $default = packLicense('CC BY 4.0');
        $other = packLicense('CC0 1.0');

        Livewire::test(LicenseSettings::class)
            ->assertSet('data.licensing_enabled', false)
            ->assertSee(LicenseResource::getUrl('index'), false)
            ->fillForm([
                'licensing_enabled' => true,
                'default_license' => $default->id,
                'allow_license_choice' => true,
                'choosable_licenses' => [$other->id],
            ])
            ->assertSeeHtml(e($default->displayDescription()))
            ->call('save')
            ->assertHasNoFormErrors();

        expect(SiteSettings::licensingEnabled())->toBeTrue()
            ->and(SiteSettings::defaultLicenseId())->toBe($default->id)
            ->and(SiteSettings::allowsLicenseChoice())->toBeTrue()
            ->and(SiteSettings::choosableLicenseIds())->toBe([$other->id]);

        Livewire::test(LicenseSettings::class)
            ->assertFormSet(['licensing_enabled' => true, 'default_license' => $default->id, 'choosable_licenses' => [$other->id]]);
    });

    it('shows the other fields only while licensing is on and the license list only while choosing is on', function () {
        Livewire::test(LicenseSettings::class)
            ->fillForm(['licensing_enabled' => false])
            ->assertFormFieldHidden('default_license')
            ->assertFormFieldHidden('allow_license_choice')
            ->assertFormFieldHidden('choosable_licenses')
            ->fillForm(['licensing_enabled' => true, 'allow_license_choice' => true])
            ->assertFormFieldVisible('default_license')
            ->assertFormFieldVisible('allow_license_choice')
            ->assertFormFieldVisible('choosable_licenses')
            ->fillForm(['allow_license_choice' => false])
            ->assertFormFieldHidden('choosable_licenses');
    });

    it('needs a default license while licensing is on, but not while it is off', function () {
        Livewire::test(LicenseSettings::class)
            ->fillForm(['licensing_enabled' => true, 'default_license' => null])
            ->call('save')
            ->assertHasFormErrors(['default_license' => 'required']);

        expect(SiteSettings::licensingEnabled())->toBeFalse();

        saveSettingsPage(['licensing_enabled' => false, 'default_license' => null], LicenseSettings::class)
            ->assertHasNoFormErrors();
    });

    it('refuses an inactive or unknown license as the default', function (bool $inactive) {
        $id = $inactive ? License::factory()->inactive()->create()->id : License::max('id') + 100;

        saveSettingsPage(['licensing_enabled' => true, 'default_license' => $id], LicenseSettings::class)
            ->assertHasFormErrors(['default_license']);

        expect(SiteSettings::defaultLicenseId())->toBeNull();
    })->with([
        'inactive' => true,
        'unknown' => false,
    ]);
});

it('is closed to staff who may not manage settings', function () {
    $this->actingAs(userWithRole(Role::Moderator));

    Livewire::test(LicenseSettings::class)->assertForbidden();
    $this->get(LicenseSettings::getUrl())->assertForbidden();
});
