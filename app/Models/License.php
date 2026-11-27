<?php

namespace App\Models;

use App\Support\SiteSettings;
use Database\Factories\LicenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;

/**
 * A license a photo can carry, e.g. "CC BY 4.0". The default pack comes with
 * the migration; admins manage the list in the admin. Names are shown as
 * written; the built-in titles and descriptions are stored in English and
 * translated when shown. Which ones people may pick is decided in App\Support\Licensing.
 */
#[Fillable(['name', 'title', 'url', 'description', 'sort_order', 'is_active'])]
class License extends Model
{
    /** @use HasFactory<LicenseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Includes photos in the trash, so a license they use is not deleted.
     *
     * @return HasMany<Photo, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)->withTrashed();
    }

    /** @param  Builder<License>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<License>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /** The short name as shown, translated when it is one of the built-in ones ("All rights reserved"). */
    public function displayName(): string
    {
        return self::translated($this->name);
    }

    /** Whether this is the license new photos get. */
    public function isDefault(): bool
    {
        return $this->getKey() === SiteSettings::defaultLicenseId();
    }

    /** What the license allows, in plain words; translated when it is one of the built-in ones. */
    public function displayDescription(): ?string
    {
        return filled($this->description) ? self::translated($this->description) : null;
    }

    /** The full title, translated when it is one of the built-in ones. */
    public function displayTitle(): ?string
    {
        return filled($this->title) ? self::translated($this->title) : null;
    }

    /**
     * The built-in texts (from the migration that adds licenses) are
     * translated in lang/*.json; anything an admin wrote is shown as written.
     */
    private static function translated(string $text): string
    {
        return Lang::has($text) ? __($text) : $text;
    }
}
