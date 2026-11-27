<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\License;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * The rules for licenses on photos. A license is set per photo: new photos
 * get the default one (Admin → Settings → Licenses), and people who may edit
 * a photo can pick another from the licenses uploaders may choose, while
 * that is allowed. People who manage settings may always pick any active
 * license. While licensing is off, licenses are kept but not shown or set.
 */
class Licensing
{
    public static function enabled(): bool
    {
        return SiteSettings::licensingEnabled();
    }

    /** The active default license, or null when none is set. */
    public static function default(): ?License
    {
        $id = SiteSettings::defaultLicenseId();

        return $id === null ? null : License::query()->active()->find($id);
    }

    /** The license id a newly uploaded photo gets. */
    public static function forNewPhoto(): ?int
    {
        return self::enabled() ? self::default()?->getKey() : null;
    }

    /** Whether $user may give a photo another license than the default. */
    public static function canChoose(?User $user): bool
    {
        return self::enabled()
            && (SiteSettings::allowsLicenseChoice() || self::managesAll($user));
    }

    /**
     * The licenses $user may pick: every active one for people who manage
     * settings, else the ones uploaders may choose (with the default), or
     * only the default while choosing is off.
     *
     * @return Collection<int, License>
     */
    public static function choosableFor(?User $user): Collection
    {
        $query = License::query()->active()->ordered();

        if (self::managesAll($user)) {
            return $query->get();
        }

        $defaultId = SiteSettings::defaultLicenseId();

        if (! SiteSettings::allowsLicenseChoice()) {
            return $query->whereKey($defaultId ?? 0)->get();
        }

        $ids = SiteSettings::choosableLicenseIds();

        if ($ids !== null) {
            $query->whereKey(array_filter([...$ids, $defaultId]));
        }

        return $query->get();
    }

    /**
     * Options for a license field: the licenses $user may pick, plus the
     * photo's current one, so saving the form never changes it unasked.
     *
     * @return array<int, string>
     */
    public static function optionsFor(?User $user, ?Photo $photo = null): array
    {
        $options = self::choosableFor($user)->mapWithKeys(fn (License $license): array => [
            $license->getKey() => $license->displayName(),
        ])->all();

        $current = $photo?->license;

        if ($current !== null && ! array_key_exists($current->getKey(), $options)) {
            $options[$current->getKey()] = $current->displayName();
        }

        return $options;
    }

    /**
     * The plain-words description of a license, with a link to its full
     * text, for helper texts under license fields.
     */
    public static function describe(mixed $licenseId): ?HtmlString
    {
        $license = filled($licenseId) ? License::query()->find((int) $licenseId) : null;

        if ($license === null) {
            return null;
        }

        $text = e($license->displayDescription() ?? $license->displayTitle() ?? '');

        if (filled($license->url)) {
            $text .= ' <a href="'.e($license->url).'" target="_blank" rel="noopener noreferrer" class="underline">'.e(__('Read the license')).'</a>';
        }

        return $text === '' ? null : new HtmlString($text);
    }

    /** Whether $user may give a photo the license $licenseId (null: none). */
    public static function allows(?User $user, ?int $licenseId, ?Photo $photo = null): bool
    {
        if ($licenseId === null || $licenseId === $photo?->license_id) {
            return true;
        }

        return self::canChoose($user)
            && self::choosableFor($user)->contains(fn (License $license): bool => $license->getKey() === $licenseId);
    }

    /**
     * What visitors see of a photo's license, or null while licensing is off
     * or the photo has none.
     *
     * @return array{name: string, title: ?string, url: ?string}|null
     */
    public static function displayFor(Photo $photo): ?array
    {
        if (! self::enabled() || $photo->license_id === null) {
            return null;
        }

        $license = $photo->license;

        return $license === null ? null : [
            'name' => $license->displayName(),
            'title' => $license->displayTitle(),
            'url' => $license->url,
        ];
    }

    private static function managesAll(?User $user): bool
    {
        return $user?->can(Permission::ManageSettings->value) ?? false;
    }
}
