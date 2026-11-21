<?php

namespace App\Support;

use App\Enums\UpdateChannel;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Finds out whether a newer OpenGallery exists, from the public releases of
 * the repository in `config('gallery.update_repository')`. It only reports:
 * nothing is downloaded or installed.
 *
 * Runs once a day in the background jobs while the admin has turned it on
 * (SiteSettings::updateChecksEnabled()). The request carries no information
 * about the gallery. The newest release of both channels is stored, so
 * changing the channel or installing a version takes effect without a request.
 *
 * @phpstan-type Release array{version: string, url: string}
 * @phpstan-type Status array{attempted_at: int, checked_at: ?int, failed: bool, stable: ?Release, dev: ?Release}
 */
final class UpdateCheck
{
    /** Setting: the result of the last check (see Status). */
    public const STATUS = 'updates.status';

    /** Shortest time between two requests, in seconds; GitHub allows few anonymous requests per address. */
    public const MIN_SECONDS = 60;

    private const STALE_SECONDS = 86400;

    private const REPOSITORY = '/^[A-Za-z0-9][A-Za-z0-9-]*\/[A-Za-z0-9_][A-Za-z0-9_.-]*$/';

    /**
     * Asks for the newest releases, unless checks are off or the last request
     * was less than MIN_SECONDS ago. A failed request keeps the last result.
     *
     * @return bool whether the stored result comes from a successful request
     */
    public static function run(): bool
    {
        $repository = self::repository();

        if (! SiteSettings::updateChecksEnabled() || $repository === null) {
            return false;
        }

        $status = self::status();
        $now = now()->getTimestamp();

        if ($now - $status['attempted_at'] < self::MIN_SECONDS) {
            return $status['checked_at'] !== null && ! $status['failed'];
        }

        $releases = self::fetch($repository);

        Setting::put(self::STATUS, $releases === null
            ? [...$status, 'attempted_at' => $now, 'failed' => true]
            : [...self::newest($releases, $repository), 'attempted_at' => $now, 'checked_at' => $now, 'failed' => false]);

        return $releases !== null;
    }

    /**
     * The release to tell the admin about: newer than this copy, in the chosen channel.
     *
     * @return Release|null
     */
    public static function available(): ?array
    {
        $installed = AppVersion::current();

        if (! SiteSettings::updateChecksEnabled() || ! AppVersion::isRelease($installed)) {
            return null;
        }

        $latest = self::status()[SiteSettings::updateChannel()->value];

        return $latest !== null && version_compare($latest['version'], $installed, '>') ? $latest : null;
    }

    /** @return Status */
    public static function status(): array
    {
        return [
            'attempted_at' => 0,
            'checked_at' => null,
            'failed' => false,
            UpdateChannel::Stable->value => null,
            UpdateChannel::Dev->value => null,
            ...(array) Setting::get(self::STATUS, []),
        ];
    }

    /** Whether the last successful check is missing or more than a day old. */
    public static function isStale(): bool
    {
        $checkedAt = self::status()['checked_at'];

        return $checkedAt === null || now()->getTimestamp() - $checkedAt >= self::STALE_SECONDS;
    }

    /** The configured repository, when it is a valid `owner/name`. */
    public static function repository(): ?string
    {
        $repository = trim((string) config('gallery.update_repository'));

        return preg_match(self::REPOSITORY, $repository) === 1 ? $repository : null;
    }

    /** @return list<mixed>|null the releases, or null when they could not be read */
    private static function fetch(string $repository): ?array
    {
        try {
            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->withUserAgent('OpenGallery-update-check')
                ->accept('application/vnd.github+json')
                ->get("https://api.github.com/repos/{$repository}/releases", ['per_page' => 30]);
        } catch (ConnectionException) {
            return null;
        }

        $releases = $response->successful() ? $response->json() : null;

        return is_array($releases) && array_is_list($releases) ? $releases : null;
    }

    /**
     * The newest release per channel. Only the tag and the two flags are used,
     * and the link is built here, so a release's texts never reach the admin.
     *
     * @param  list<mixed>  $releases
     * @return array{stable: ?Release, dev: ?Release}
     */
    private static function newest(array $releases, string $repository): array
    {
        $newest = [UpdateChannel::Stable->value => null, UpdateChannel::Dev->value => null];

        foreach ($releases as $release) {
            $tag = is_array($release) ? ($release['tag_name'] ?? null) : null;

            if (! is_string($tag) || ! str_starts_with($tag, 'v') || ($release['draft'] ?? true) !== false) {
                continue;
            }

            $version = AppVersion::fromTag($tag);

            if (! AppVersion::isRelease($version)) {
                continue;
            }

            $candidate = ['version' => $version, 'url' => "https://github.com/{$repository}/releases/tag/{$tag}"];
            $isFinished = ($release['prerelease'] ?? true) === false && ! str_contains($version, '-');

            foreach ($isFinished ? UpdateChannel::cases() : [UpdateChannel::Dev] as $channel) {
                $current = $newest[$channel->value];

                if ($current === null || version_compare($version, $current['version'], '>')) {
                    $newest[$channel->value] = $candidate;
                }
            }
        }

        return $newest;
    }
}
