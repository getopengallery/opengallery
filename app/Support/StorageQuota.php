<?php

namespace App\Support;

use App\Models\User;
use App\Support\Statistics\LibraryStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * How much photo storage each person may use, while storage limits are on
 * (Settings → Storage). A person has their own limit (storage_quota_bytes),
 * the site default (empty), or no limit (storage_unlimited, which accounts
 * from before limits existed get). Usage is the originals and generated
 * files of every photo they uploaded, trashed ones included (their files
 * are still stored), counted live so a check right after an upload or a
 * deletion is exact.
 */
class StorageQuota
{
    public const BYTES_PER_GB = 1024 ** 3;

    /** Bytes the person's photos take now. */
    public static function used(User $user): int
    {
        return (int) LibraryStats::originals()
            ->join('photos', 'photos.id', '=', 'media.model_id')
            ->where('photos.uploader_id', $user->getKey())
            ->sum(DB::raw('media.size + '.LibraryStats::generatedSql()));
    }

    /** Bytes the person may store, or null when they have no limit. */
    public static function limit(User $user): ?int
    {
        if (! SiteSettings::storageLimitsEnabled() || $user->storage_unlimited) {
            return null;
        }

        return $user->storage_quota_bytes ?? self::gbToBytes(SiteSettings::defaultStorageQuotaGb());
    }

    /** Bytes still free, or null when the person has no limit. */
    public static function remaining(User $user): ?int
    {
        $limit = self::limit($user);

        return $limit === null ? null : max(0, $limit - self::used($user));
    }

    /** Whether a file of $bytes still fits (exactly filling the quota is fine). */
    public static function allows(User $user, int $bytes): bool
    {
        $remaining = self::remaining($user);

        return $remaining === null || $bytes <= $remaining;
    }

    /** The message shown when an upload does not fit. */
    public static function exceededMessage(User $user): string
    {
        return __('Not enough storage left. You can store up to :quota of photos.', [
            'quota' => Number::fileSize((int) self::limit($user), maxPrecision: 1),
        ]);
    }

    public static function gbToBytes(int $gb): int
    {
        return $gb * self::BYTES_PER_GB;
    }

    public static function bytesToGb(?int $bytes): ?int
    {
        return $bytes === null ? null : (int) round($bytes / self::BYTES_PER_GB);
    }
}
