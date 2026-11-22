<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Clusters\Settings\Pages\ActivityLogSettings;
use App\Filament\Clusters\Settings\Pages\BackgroundJobSettings;
use App\Filament\Clusters\Settings\Pages\EmailSettings;
use App\Filament\Clusters\Settings\Pages\UpdateSettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Documentation;
use App\Support\AppVersion;
use App\Support\Dashboard\SiteHealth as Checks;
use App\Support\UpdateCheck;
use Illuminate\Support\Number;

/**
 * For people who change site settings: problems with the installation
 * (App\Support\Dashboard\SiteHealth), each with a link to what fixes it.
 */
class SiteHealth extends DashboardWidget
{
    protected string $view = 'filament.widgets.site-health';

    public static function dashboardLabel(): string
    {
        return __('Site health');
    }

    public static function dashboardDescription(): ?string
    {
        return __('Problems with the installation and how to fix them.');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ManageSettings);
    }

    /**
     * @param  array{key: string, severity: string, count: ?int}  $problem
     * @return array{title: string, text: string, url: string, link: string, severity: string}
     */
    public static function describe(array $problem): array
    {
        return [
            ...self::explain($problem),
            'severity' => match ($problem['severity']) {
                Checks::DANGER => __('Problem'),
                Checks::WARNING => __('Warning'),
                default => __('Note'),
            },
        ];
    }

    /**
     * @param  array{key: string, severity: string, count: ?int}  $problem
     * @return array{title: string, text: string, url: string, link: string}
     */
    private static function explain(array $problem): array
    {
        $count = $problem['count'] ?? 0;
        $settings = fn (string $page): array => ['url' => $page::getUrl(), 'link' => $page::menuPath()];
        $docs = fn (string $doc, string $anchor): array => ['url' => Documentation::getUrl(['doc' => $doc]).'#'.$anchor, 'link' => __('How to fix it')];
        $installation = 'user/getting-started/installation';

        return match ($problem['key']) {
            'pending-migrations' => ['title' => __('The database needs an update'), 'text' => trans_choice(':count database update has not been run. Finish the upgrade.|:count database updates have not been run. Finish the upgrade.', $count), ...$docs($installation, 'upgrading')],
            'storage-not-writable' => ['title' => __('Photos cannot be saved'), 'text' => __('The storage folder is not writable, so uploads fail.'), ...$docs($installation, 'where-photos-are-stored')],
            'debug-mode' => ['title' => __('Debug mode is on'), 'text' => __('Visitors can see error details. Set APP_DEBUG to false.'), ...$docs($installation, 'the-server-configuration-file')],
            'queue-stuck' => ['title' => __('Thumbnails are not being made'), 'text' => trans_choice(':count job has been waiting for more than :minutes minutes. Is the queue worker running?|:count jobs have been waiting for more than :minutes minutes. Is the queue worker running?', $count, ['minutes' => Checks::STUCK_JOB_MINUTES]), ...$docs($installation, 'thumbnails-in-the-background-optional')],
            'failed-jobs' => ['title' => __('Background jobs failed'), 'text' => trans_choice(':count background job failed.|:count background jobs failed.', $count), ...$docs($installation, 'thumbnails-in-the-background-optional')],
            'missing-thumbnails' => ['title' => __('Photos without thumbnails'), 'text' => trans_choice(':count photo has no thumbnail yet.|:count photos have no thumbnail yet.', $count), ...$docs($installation, 'thumbnails-in-the-background-optional')],
            'low-disk-space' => ['title' => __('Disk space is low'), 'text' => __(':size free on the server.', ['size' => Number::fileSize($count, precision: 1)]), ...$docs($installation, 'where-photos-are-stored')],
            'opcache-off' => ['title' => __('OPcache is off'), 'text' => __('PHP prepares the gallery anew for every page, which makes every page slower. Turn on OPcache in your hosting control panel.'), ...$docs($installation, 'speed')],
            'caches-not-saved' => ['title' => __('Speed-up files cannot be saved'), 'text' => __('The gallery could not save its prepared files in the bootstrap/cache folder, so every page is slower. Make that folder writable.'), ...$docs($installation, 'speed')],
            'mail-not-sent' => ['title' => __('E-mails are not sent'), 'text' => __('Invitations, password resets and notices do not reach anyone.'), ...$settings(EmailSettings::class)],
            'background-jobs-idle' => ['title' => __('Background jobs do not run'), 'text' => __('Old activity log entries and unfinished uploads are not removed.'), ...$settings(BackgroundJobSettings::class)],
            'activity-log-off' => ['title' => __('The activity log is off'), 'text' => __('Logins, visits and changes are not recorded.'), ...$settings(ActivityLogSettings::class)],
            'update-available' => ['title' => __('Update available'), 'text' => __('OpenGallery :version is available. This gallery runs :current.', ['version' => UpdateCheck::available()['version'] ?? '–', 'current' => AppVersion::current()]), ...$settings(UpdateSettings::class)],
            default => ['title' => $problem['key'], 'text' => '', 'url' => SettingsCluster::getUrl(), 'link' => __('Settings')],
        };
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return Checks::report();
    }
}
