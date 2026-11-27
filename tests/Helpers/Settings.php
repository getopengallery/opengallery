<?php

use App\Enums\BackgroundJobMode;
use App\Filament\Clusters\Settings\Pages\EmailSettings;
use App\Models\Setting;
use App\Support\SiteSettings;
use Livewire\Livewire;

/**
 * Stores $settings and applies them like at boot.
 *
 * @param  array<string, mixed>  $settings  setting key => value
 */
function applySiteSettings(array $settings): void
{
    foreach ($settings as $key => $value) {
        Setting::put($key, $value);
    }
    SiteSettings::apply();
}

/** Stores the background jobs mode. */
function chooseBackgroundJobs(BackgroundJobMode $mode): void
{
    Setting::put(SiteSettings::BACKGROUND_JOBS, $mode->value);
}

/** Stores $locale as the site's default language and applies it. */
function useDefaultLocale(string $locale): void
{
    Setting::put(SiteSettings::LOCALE, $locale);
    SiteSettings::apply();
}

/**
 * Fills and saves a Filament settings page (the e-mail page by default).
 *
 * @param  array<string, mixed>  $data
 * @param  class-string  $page
 */
function saveSettingsPage(array $data, string $page = EmailSettings::class): mixed
{
    return Livewire::test($page)->fillForm($data)->call('save');
}

/** Turns photo compression on, scaling to $maxPx on the longest side (null: not) and saving at $quality percent (null: not). */
function useCompression(?int $maxPx = null, ?int $quality = null): void
{
    Setting::put(SiteSettings::COMPRESSION_ENABLED, true);
    Setting::put(SiteSettings::COMPRESSION_RESIZE, $maxPx !== null);
    Setting::put(SiteSettings::COMPRESSION_MAX_PX, $maxPx ?? SiteSettings::COMPRESSION_MAX_PX_DEFAULT);
    Setting::put(SiteSettings::COMPRESSION_REENCODE, $quality !== null);
    Setting::put(SiteSettings::COMPRESSION_QUALITY, $quality ?? SiteSettings::COMPRESSION_QUALITY_DEFAULT);
}
