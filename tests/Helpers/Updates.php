<?php

use App\Enums\UpdateChannel;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use App\Support\UpdateCheck;
use App\Support\Updates\ReleaseManifest;
use App\Support\Updates\UpdateInstaller;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const UPDATE_API = 'api.github.com/repos/getopengallery/opengallery/releases*';

/** @return array<string, mixed> a release as the GitHub API returns it */
function githubRelease(string $tag, bool $prerelease = false, bool $draft = false): array
{
    return [
        'tag_name' => $tag,
        'prerelease' => $prerelease,
        'draft' => $draft,
        'name' => '<script>alert(1)</script>',
        'html_url' => 'https://evil.example/download',
    ];
}

/** Switches update checks on for $channel. */
function turnOnUpdateChecks(UpdateChannel $channel = UpdateChannel::Stable): void
{
    Setting::put(SiteSettings::UPDATE_CHECKS, true);
    Setting::put(SiteSettings::UPDATE_CHANNEL, $channel->value);
}

/**
 * A zip with the files every release needs, plus $files.
 *
 * @param  array<string, string>  $files  path inside the release => contents
 * @return array{path: string, files: int, bytes: int}
 */
function releaseZip(string $folder, string $version, array $files = []): array
{
    $contents = [
        'VERSION' => "{$version}\n",
        'artisan' => '<?php',
        'bootstrap/app.php' => '<?php',
        'public/index.php' => '<?php',
        'public/build/manifest.json' => '{}',
        'vendor/autoload.php' => '<?php',
        ...$files,
    ];
    $path = "{$folder}/opengallery-v{$version}.zip";
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($contents as $name => $body) {
        $zip->addFromString("opengallery/{$name}", $body);
    }

    $zip->close();

    return ['path' => $path, 'files' => count($contents), 'bytes' => array_sum(array_map('strlen', $contents))];
}

/**
 * A release manifest for $zip, signed with $secret.
 *
 * @param  array{path: string, files: int, bytes: int}  $zip
 * @param  array<string, mixed>  $overrides  manifest fields to change
 */
function signedManifest(array $zip, string $version, string $secret, array $overrides = []): ReleaseManifest
{
    return (new ReleaseManifest(...[
        'version' => $version,
        'channel' => ReleaseManifest::channelOf($version),
        'file' => "opengallery-v{$version}.zip",
        'size' => (int) filesize($zip['path']),
        'unpackedSize' => $zip['bytes'],
        'files' => $zip['files'],
        'sha256' => hash_file('sha256', $zip['path']),
        'requiresPhp' => '8.3.0',
        ...$overrides,
    ]))->signedWith($secret);
}

/** Fakes the release host: serves $manifest and ranges of the zip at $zipPath. */
function fakeReleaseHost(string $zipPath, ReleaseManifest $manifest): void
{
    $bytes = (string) file_get_contents($zipPath);

    Http::fake(function (Request $request) use ($bytes, $manifest) {
        if (str_ends_with($request->url(), '.update.json')) {
            return Http::response(json_encode($manifest->toArray()));
        }

        preg_match('/bytes=(\d+)-(\d+)/', (string) ($request->header('Range')[0] ?? ''), $range);

        return Http::response(substr($bytes, (int) $range[1], (int) $range[2] - (int) $range[1] + 1), 206);
    });
}

/** Stores a finished update check that offers $version on $channel. */
function offerRelease(string $version, UpdateChannel $channel = UpdateChannel::Stable): void
{
    Setting::put(SiteSettings::UPDATE_CHECKS, true);
    Setting::put(SiteSettings::UPDATE_CHANNEL, $channel->value);
    Setting::put(UpdateCheck::STATUS, [
        'attempted_at' => now()->getTimestamp(),
        'checked_at' => now()->getTimestamp(),
        'failed' => false,
        $channel->value => ['version' => $version, 'url' => "https://github.com/getopengallery/opengallery/releases/tag/v{$version}"],
    ]);
}

/** Starts the installer as $user and advances it until the release is ready to switch over. */
function prepareRelease(User $user): void
{
    UpdateInstaller::start($user);

    do {
        $progress = UpdateInstaller::advance();
    } while ($progress['phase'] !== UpdateInstaller::PHASE_READY);
}

/**
 * Every file under $base.
 *
 * @return array<string, string> relative path => contents
 */
function siteFiles(string $base): array
{
    $files = [];

    foreach (File::allFiles($base, true) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    ksort($files);

    return $files;
}

/**
 * Fakes git, tar, Composer and npm: tar "exports" an app file, npm builds a
 * manifest and leaves node_modules behind, as the real tools would.
 *
 * @param  array<string, mixed>  $results  command prefix => fake result
 */
function fakeReleaseTools(array $results = []): void
{
    test()->exportedTo = null;

    Process::fake(function (PendingProcess $process) use ($results) {
        $command = implode(' ', (array) $process->command);

        foreach ($results as $prefix => $result) {
            if (str_starts_with($command, $prefix)) {
                return $result;
            }
        }

        if (str_starts_with($command, 'tar ')) {
            $target = $process->command[array_search('-C', $process->command, true) + 1];
            test()->exportedTo = $target;
            File::put("{$target}/artisan", '<?php');
            File::put("{$target}/VERSION", "1.3.0-dev\n");
            File::put("{$target}/package.json", '{}');
            File::put("{$target}/vite.config.js", '');
        }

        if ($command === 'npm ci') {
            File::ensureDirectoryExists("{$process->path}/node_modules/vite");
        }

        if ($command === 'npm run build') {
            File::ensureDirectoryExists("{$process->path}/public/build");
            File::put("{$process->path}/public/build/manifest.json", '{}');
        }

        return Process::result(output: match ($command) {
            'node --version' => 'v22.12.0',
            default => '',
        });
    });
}
