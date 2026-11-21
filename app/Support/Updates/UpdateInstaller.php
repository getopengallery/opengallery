<?php

namespace App\Support\Updates;

use App\Enums\Role;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\SiteSettings;
use App\Support\UpdateCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Prepares a one-click update while the site keeps running: signed release
 * information, download in portions, checksum, and unpacking into a work
 * folder. handOver() then starts the standalone updater
 * (`resources/updater/runner.php`), which takes the site offline, backs up the
 * database, replaces the code, migrates, checks the result and rolls back on
 * any failure. It runs from Laravel's maintenance file, before any application
 * code is loaded, so it keeps working while that code is being replaced.
 *
 * @phpstan-type State array{phase: string, version: string, tag: string, from: string, manifest: array<string, mixed>, downloaded: int, entry: int, total: int, user_id: int, updated_at: int}
 */
final class UpdateInstaller
{
    public const PHASE_DOWNLOADING = 'downloading';

    public const PHASE_UNPACKING = 'unpacking';

    public const PHASE_READY = 'ready';

    private const CHUNK_BYTES = 4 * 1024 * 1024;

    private const STEP_SECONDS = 6.0;

    private const ABANDONED_SECONDS = 86400;

    /** Results older than this are no longer shown. */
    private const RESULT_DAYS = 7;

    private static ?string $directory = null;

    private static ?string $maintenanceFile = null;

    /** Only super administrators, and only while the server allows it. */
    public static function allowed(?User $user): bool
    {
        return (bool) config('gallery.update_install') && ($user?->hasRole(Role::SuperAdmin->value) ?? false);
    }

    /**
     * Begins preparing the available update, after checking its signature
     * and everything the server needs.
     *
     * @throws UpdateFailed
     */
    public static function start(User $user): void
    {
        if (! self::allowed($user)) {
            throw new UpdateFailed(__('You may not install updates.'));
        }

        if (self::isRunning()) {
            throw new UpdateFailed(__('An update is already being prepared.'));
        }

        $update = UpdateCheck::available();

        if ($update === null) {
            throw new UpdateFailed(__('No update is available.'));
        }

        if (($problems = UpdatePreflight::serverProblems()) !== []) {
            throw new UpdateFailed(implode(' ', $problems));
        }

        $tag = 'v'.$update['version'];
        $manifest = self::fetchManifest($tag);

        if ($manifest->version !== $update['version'] || version_compare($manifest->version, AppVersion::current(), '<=')) {
            throw new UpdateFailed(__('The release information is not valid.'));
        }

        $manifest->verify(SiteSettings::updateChannel());

        if (($problems = UpdatePreflight::problems($manifest, self::directory(), self::maintenanceFile())) !== []) {
            throw new UpdateFailed(implode(' ', $problems));
        }

        File::deleteDirectory(self::directory());
        File::ensureDirectoryExists(self::directory(), 0700);

        self::save([
            'phase' => self::PHASE_DOWNLOADING,
            'version' => $manifest->version,
            'tag' => $tag,
            'from' => AppVersion::current(),
            'manifest' => $manifest->toArray(),
            'downloaded' => 0,
            'entry' => 0,
            'total' => 0,
            'user_id' => $user->id,
            'updated_at' => now()->getTimestamp(),
        ]);
    }

    /**
     * Does the next portion of the preparation. On failure the work folder is removed.
     *
     * @return array{phase: string, percent: int}
     *
     * @throws UpdateFailed
     */
    public static function advance(): array
    {
        $state = self::state();

        if ($state === null || ! in_array($state['phase'], [self::PHASE_DOWNLOADING, self::PHASE_UNPACKING, self::PHASE_READY], true)) {
            throw new UpdateFailed(__('No update is being prepared.'));
        }

        $manifest = ReleaseManifest::fromArray($state['manifest']);
        $deadline = microtime(true) + self::STEP_SECONDS;

        try {
            if ($state['phase'] === self::PHASE_DOWNLOADING) {
                $state = self::download($state, $manifest, $deadline);
            } elseif ($state['phase'] === self::PHASE_UNPACKING) {
                $state['entry'] = ReleaseArchive::extract(self::zipPath(), self::directory().'/staging', $state['entry'], $deadline);

                if ($state['entry'] >= $state['total']) {
                    $state['phase'] = self::PHASE_READY;
                }
            }
        } catch (UpdateFailed $failure) {
            self::cancel();

            throw $failure;
        }

        $state['updated_at'] = now()->getTimestamp();
        self::save($state);

        return ['phase' => $state['phase'], 'percent' => self::percent($state, $manifest)];
    }

    /**
     * Takes the site offline and gives the browser the one-time key of the
     * standalone updater. The key is kept only as a hash.
     *
     * @return string the one-time key
     *
     * @throws UpdateFailed
     */
    public static function handOver(User $user): string
    {
        $state = self::state();

        if (! self::allowed($user) || $state === null || $state['phase'] !== self::PHASE_READY || $state['user_id'] !== $user->id) {
            throw new UpdateFailed(__('No update is ready to install.'));
        }

        $manifest = ReleaseManifest::fromArray($state['manifest']);
        $manifest->verify(SiteSettings::updateChannel());

        if (($problems = UpdatePreflight::problems($manifest, self::directory(), self::maintenanceFile())) !== []) {
            self::cancel();

            throw new UpdateFailed(implode(' ', $problems));
        }

        $token = bin2hex(random_bytes(32));
        $directory = self::directory();

        File::copy(resource_path('updater/runner.php'), "{$directory}/runner.php");

        self::save([
            'phase' => 'backup',
            'version' => $state['version'],
            'from' => $state['from'],
            'token_hash' => hash('sha256', $token),
            'base' => base_path(),
            'entry_file' => self::maintenanceFile(),
            'staging' => "{$directory}/staging",
            'plan' => ReleasePlan::build("{$directory}/staging", base_path(), "{$directory}/previous"),
            'swapped' => 0,
            'migrated' => false,
            'home_url' => url('/'),
            'return_url' => route('filament.admin.settings.pages.updates'),
            'check_url' => route('login'),
            'texts' => self::runnerTexts($state['version']),
            'updated_at' => now()->getTimestamp(),
        ]);

        $entry = self::maintenanceFile();
        $code = "<?php\n\n// Written by the one-click updater; removed when it finishes.\n"
            ."if (is_file(__DIR__.'/../app/updates/runner.php')) {\n"
            ."    require __DIR__.'/../app/updates/runner.php';\n"
            ."    OpenGalleryUpdater::handle(__DIR__.'/../app/updates');\n"
            ."} else {\n"
            ."    @unlink(__FILE__);\n"
            ."}\n";

        if (@file_put_contents("{$entry}.tmp", $code, LOCK_EX) === false || ! @rename("{$entry}.tmp", $entry)) {
            @unlink("{$entry}.tmp");
            File::deleteDirectory($directory);

            throw new UpdateFailed(__('The update could not be started.'));
        }

        return $token;
    }

    /** Stops a preparation. The site has not been touched yet. */
    public static function cancel(): void
    {
        if (! file_exists(self::maintenanceFile())) {
            File::deleteDirectory(self::directory());
        }
    }

    public static function isRunning(): bool
    {
        return self::state() !== null;
    }

    /** @return array{phase: string, version: string}|null the preparation in progress */
    public static function preparing(): ?array
    {
        $state = self::state();

        return $state !== null && in_array($state['phase'], [self::PHASE_DOWNLOADING, self::PHASE_UNPACKING, self::PHASE_READY], true)
            ? ['phase' => $state['phase'], 'version' => $state['version']]
            : null;
    }

    /**
     * How the last update ended, for the settings page.
     *
     * @return array{status: string, version: string, from: string, at: int, detail: ?string}|null
     */
    public static function lastResult(): ?array
    {
        $file = self::resultFile();
        $result = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($result) || ! isset($result['status'], $result['version'], $result['from'], $result['at'])
            || now()->getTimestamp() - (int) $result['at'] > self::RESULT_DAYS * 86400) {
            return null;
        }

        return [...$result, 'detail' => $result['detail'] ?? null];
    }

    /** Removes preparations nobody finished and leftovers of a finished update (background jobs). */
    public static function prune(): void
    {
        $state = self::state();

        if ($state !== null && ! file_exists(self::maintenanceFile())
            && now()->getTimestamp() - (int) ($state['updated_at'] ?? 0) > self::ABANDONED_SECONDS) {
            File::deleteDirectory(self::directory());
        }

        foreach (glob(self::directory().'-old-*', GLOB_ONLYDIR) ?: [] as $leftover) {
            File::deleteDirectory($leftover);
        }
    }

    /** Points the work folder and maintenance file elsewhere (tests); null restores them. */
    public static function usePaths(?string $directory, ?string $maintenanceFile): void
    {
        self::$directory = $directory;
        self::$maintenanceFile = $maintenanceFile;
    }

    public static function directory(): string
    {
        return self::$directory ?? storage_path('app/updates');
    }

    private static function maintenanceFile(): string
    {
        return self::$maintenanceFile ?? storage_path('framework/maintenance.php');
    }

    private static function resultFile(): string
    {
        return dirname(self::directory()).'/update-result.json';
    }

    private static function zipPath(): string
    {
        return self::directory().'/release.zip';
    }

    /** @throws UpdateFailed */
    private static function fetchManifest(string $tag): ReleaseManifest
    {
        try {
            $response = self::client(10)->get(self::assetUrl($tag, "opengallery-{$tag}.update.json"));
        } catch (ConnectionException) {
            throw new UpdateFailed(__('The release could not be downloaded. Try again later.'));
        }

        if ($response->notFound()) {
            throw new UpdateFailed(__('This release cannot be installed in one click. Install it by hand.'));
        }

        if (! $response->successful()) {
            throw new UpdateFailed(__('The release could not be downloaded. Try again later.'));
        }

        return ReleaseManifest::fromArray(json_decode($response->body(), true));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     *
     * @throws UpdateFailed
     */
    private static function download(array $state, ReleaseManifest $manifest, float $deadline): array
    {
        $url = self::assetUrl($state['tag'], $manifest->file);
        $part = self::zipPath().'.part';

        while ($state['downloaded'] < $manifest->size) {
            $state['downloaded'] = self::downloadChunk($url, $part, $state['downloaded'], $manifest->size);

            if (microtime(true) >= $deadline) {
                break;
            }
        }

        if ($state['downloaded'] < $manifest->size) {
            return $state;
        }

        if (! hash_equals($manifest->sha256, (string) hash_file('sha256', $part)) || ! @rename($part, self::zipPath())) {
            throw new UpdateFailed(__('The downloaded release is not valid.'));
        }

        ReleaseArchive::validate(self::zipPath(), $manifest);

        return [...$state, 'phase' => self::PHASE_UNPACKING, 'total' => ReleaseArchive::count(self::zipPath())];
    }

    /**
     * @return int bytes downloaded so far
     *
     * @throws UpdateFailed
     */
    private static function downloadChunk(string $url, string $part, int $from, int $size): int
    {
        $last = min($from + self::CHUNK_BYTES, $size) - 1;
        $chunk = "{$part}.chunk";
        $failed = new UpdateFailed(__('The release could not be downloaded. Try again later.'));

        try {
            $response = self::client(60)->withHeaders(['Range' => "bytes={$from}-{$last}"])->sink($chunk)->get($url);
        } catch (ConnectionException) {
            throw $failed;
        }

        $received = is_file($chunk) ? (int) filesize($chunk) : 0;
        $isWholeFile = $response->status() === 200 && $from === 0 && $received === $size;

        if (! $isWholeFile && ($response->status() !== 206 || $received !== $last - $from + 1)) {
            @unlink($chunk);

            throw $failed;
        }

        $in = fopen($chunk, 'rb');
        $out = @fopen($part, 'ab');

        if ($in === false || $out === false || stream_copy_to_stream($in, $out) !== $received) {
            throw new UpdateFailed(__('The release could not be saved. Check that the server has enough disk space.'));
        }

        fclose($in);
        fclose($out);
        unlink($chunk);

        return $from + $received;
    }

    private static function client(int $timeout): PendingRequest
    {
        return Http::connectTimeout(5)->timeout($timeout)->withUserAgent('OpenGallery-updater');
    }

    private static function assetUrl(string $tag, string $file): string
    {
        $repository = UpdateCheck::repository();

        if ($repository === null) {
            throw new UpdateFailed(__('No update repository is configured.'));
        }

        return rtrim((string) config('gallery.update_download_url'), '/')."/{$repository}/releases/download/{$tag}/{$file}";
    }

    /** @param  array<string, mixed>  $state */
    private static function percent(array $state, ReleaseManifest $manifest): int
    {
        return match ($state['phase']) {
            self::PHASE_DOWNLOADING => (int) floor(50 * $state['downloaded'] / $manifest->size),
            self::PHASE_UNPACKING => 50 + (int) floor(50 * $state['entry'] / max(1, $state['total'])),
            default => 100,
        };
    }

    /** @return array<string, string> the updater cannot load translations, so it gets its texts here */
    private static function runnerTexts(string $version): array
    {
        return [
            'lang' => app()->getLocale(),
            'title' => __('Updating OpenGallery'),
            'heading' => __('Installing version :version', ['version' => $version]),
            'keep_open' => __('Keep this page open. The gallery comes back by itself.'),
            'backup' => __('Backing up the database'),
            'swap' => __('Replacing the files'),
            'migrate' => __('Updating the database'),
            'verify' => __('Checking the new version'),
            'done' => __('Finishing'),
            'rollback' => __('Something went wrong. Restoring the previous version'),
            'restore' => __('Restoring the database'),
            'aborted' => __('Finishing the restore'),
            'stuck' => __('The previous version could not be restored automatically. Restore your backup by hand, as described under Upgrading in the documentation.'),
            'retry' => __('Try again'),
            'unavailable_title' => __('Back in a moment'),
            'unavailable' => __('This gallery is being updated and will be back in a few minutes.'),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function state(): ?array
    {
        $file = self::directory().'/state.json';
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($state) ? $state : null;
    }

    /** @param  array<string, mixed>  $state */
    private static function save(array $state): void
    {
        $file = self::directory().'/state.json';

        if (@file_put_contents("{$file}.tmp", json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! @rename("{$file}.tmp", $file)) {
            throw new UpdateFailed(__('The update could not be started.'));
        }
    }
}
