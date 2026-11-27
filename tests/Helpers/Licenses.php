<?php

use App\Models\License;
use App\Models\Setting;
use App\Support\SiteSettings;

/** One of the licenses the migration comes with, e.g. "CC BY 4.0". */
function packLicense(string $name): License
{
    return License::where('name', $name)->firstOrFail();
}

/**
 * Turns licensing on with $default (the "All rights reserved" license unless given) as the default license.
 * $settings overrides or adds setting keys, e.g. SiteSettings::LICENSES_ALLOW_CHOICE => false.
 *
 * @param  array<string, mixed>  $settings
 */
function enableLicensing(?License $default = null, array $settings = []): License
{
    $default ??= packLicense('All rights reserved');

    foreach ([...[
        SiteSettings::LICENSING => true,
        SiteSettings::LICENSE_DEFAULT => $default->id,
    ], ...$settings] as $key => $value) {
        Setting::put($key, $value);
    }

    return $default;
}
