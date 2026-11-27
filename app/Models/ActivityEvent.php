<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Extensions\Manifest;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Support\ActivityLog;
use Database\Factories\ActivityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One logged event: a visit (login, album view) or a change made by someone
 * (album created, user's roles changed, photos uploaded, settings changed...).
 * Written by App\Support\ActivityLog; pruned after
 * `gallery.activity_retention_days` by `php artisan model:prune`.
 *
 * user_id is always the person who did it (null for guests and the system).
 */
#[Fillable(['type', 'user_id', 'album_id', 'visitor', 'subject_type', 'subject_id', 'properties', 'created_at'])]
class ActivityEvent extends Model
{
    /** @use HasFactory<ActivityEventFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class)->withTrashed();
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return Builder<ActivityEvent> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('gallery.activity_retention_days')));
    }

    // ---- Presentation --------------------------------------------------------

    /** Who did it: a member's name, "A guest" or "System". */
    public function actorName(): string
    {
        if ($this->isSignUp()) {
            return $this->subjectLabel() ?? __('Someone');
        }

        if ($this->type === ActivityType::LoginFailed || $this->type === ActivityType::LockedOut) {
            return __('Someone');
        }

        return $this->user?->name ?? ($this->type?->isVisit() || $this->type?->isSecurity() ? __('A guest') : __('System'));
    }

    /**
     * What happened, without the subject: "changed album", "uploaded 12 photos to".
     * Translated when shown; the subject's name follows (see showsSubject()).
     */
    public function action(): string
    {
        $noun = ActivityLog::NOUNS[$this->subject_type] ?? 'item';
        $count = (int) ($this->properties['count'] ?? 0);

        if ($this->isBatch()) {
            $items = ['items' => self::countedNoun($noun, $count)];

            return match ($this->type) {
                ActivityType::Created => __('created :items', $items),
                ActivityType::Updated => __('changed :items', $items),
                ActivityType::Deleted => ($this->properties['trash'] ?? false) ? __('moved :items to the trash', $items) : __('permanently deleted :items', $items),
                ActivityType::Restored => __('restored :items', $items),
                default => __('did something'),
            };
        }

        $item = ['item' => self::noun($noun)];
        $provider = ['provider' => filled($this->properties['provider'] ?? null) ? Str::headline((string) $this->properties['provider']) : __('identity provider')];

        return match ($this->type) {
            ActivityType::Login => __('logged in'),
            ActivityType::AlbumView => __('opened'),
            ActivityType::PhotoDownload => __('downloaded'),
            ActivityType::ZipDownload => $this->album_id
                ? trans_choice('downloaded :count photo from|downloaded :count photos from', $count)
                : trans_choice('downloaded :count photo|downloaded :count photos', $count),
            ActivityType::Created => $this->isSignUp() ? __('created an account') : __('created :item', $item),
            ActivityType::Updated => __('changed :item', $item),
            ActivityType::Deleted => __('deleted :item', $item),
            ActivityType::Restored => __('restored :item', $item),
            ActivityType::PhotosUploaded => trans_choice('uploaded :count photo to|uploaded :count photos to', $count),
            ActivityType::PhotosAdded => trans_choice('added :count photo to|added :count photos to', $count),
            ActivityType::PhotosRemoved => trans_choice('removed :count photo from|removed :count photos from', $count),
            ActivityType::SettingsChanged => __('changed the site settings'),
            ActivityType::ThemeActivated => __('activated theme'),
            ActivityType::PluginEnabled => __('turned on plugin'),
            ActivityType::PluginDisabled => __('turned off plugin'),
            ActivityType::ThemeInstalled => __('uploaded theme'),
            ActivityType::PluginInstalled => __('uploaded plugin'),
            ActivityType::PhotosReordered => __('reordered the photos in'),
            ActivityType::AlbumsReordered => $this->subject_id ? __('reordered the albums in') : __('reordered the top-level albums'),
            ActivityType::LoginFailed => ($this->properties['known'] ?? false) ? __('used a wrong password for') : __('tried to log in with an unknown e-mail address'),
            ActivityType::LockedOut => $this->subject_id ? __('was locked out after too many login attempts for') : __('was locked out after too many login attempts'),
            ActivityType::PasswordReset => __('reset their password'),
            ActivityType::AlbumPasswordFailed => __('entered a wrong password for'),
            ActivityType::AlbumGuestCodeFailed => __('entered a wrong guest code for'),
            ActivityType::AccountLinked => $this->isOwnAccount() ? __('linked a :provider account to their account', $provider) : __('linked a :provider account to', $provider),
            ActivityType::AccountUnlinked => $this->isOwnAccount() ? __('unlinked a :provider account from their account', $provider) : __('unlinked a :provider account from', $provider),
            ActivityType::SessionsSignedOut => $this->isOwnAccount()
                ? trans_choice('signed out one of their sessions|signed out :count of their sessions', $count)
                : trans_choice('signed out :count session of|signed out :count sessions of', $count),
            default => __('did something'),
        };
    }

    /** A subject noun (ActivityLog::NOUNS) in the current language: "album". */
    private static function noun(string $noun): string
    {
        return match ($noun) {
            'album' => __('album'),
            'user' => __('user'),
            'group' => __('group'),
            'role' => __('role'),
            'photo' => __('photo'),
            'page' => __('page'),
            'license' => __('license'),
            default => __('item'),
        };
    }

    /** A counted subject noun in the current language: "12 photos". */
    private static function countedNoun(string $noun, int $count): string
    {
        return match ($noun) {
            'album' => trans_choice(':count album|:count albums', $count),
            'user' => trans_choice(':count user|:count users', $count),
            'group' => trans_choice(':count group|:count groups', $count),
            'role' => trans_choice(':count role|:count roles', $count),
            'photo' => trans_choice(':count photo|:count photos', $count),
            'page' => trans_choice(':count page|:count pages', $count),
            'license' => trans_choice(':count license|:count licenses', $count),
            default => trans_choice(':count item|:count items', $count),
        };
    }

    /** Name of the thing acted on, as it was at the time. */
    public function subjectLabel(): ?string
    {
        if ($this->type === ActivityType::AlbumView || ($this->type === ActivityType::ZipDownload && $this->album_id)) {
            return $this->album?->title ?? __('a deleted album');
        }

        if ($this->type === ActivityType::ZipDownload) {
            return null;
        }

        if ($this->isSignUp()) {
            return $this->properties['label'] ?? null;
        }

        // Theme and plugin names may be translation keys from their manifests.
        if (in_array($this->type, [ActivityType::ThemeActivated, ActivityType::PluginEnabled, ActivityType::PluginDisabled, ActivityType::ThemeInstalled, ActivityType::PluginInstalled], true)) {
            return Manifest::text($this->properties['label'] ?? null);
        }

        return ActivityLog::label($this->subject) ?? ($this->properties['label'] ?? null);
    }

    /** Where to look at the subject, if it still exists and the current user may open it. */
    public function subjectUrl(): ?string
    {
        $subject = in_array($this->type, [ActivityType::AlbumView, ActivityType::ZipDownload], true) ? $this->album : $this->subject;

        return match (true) {
            $subject instanceof Album && ! $subject->trashed() => route('albums.show', $subject),
            $subject instanceof User && UserResource::canAccess() => UserResource::getUrl('edit', ['record' => $subject]),
            $subject instanceof Group && GroupResource::canAccess() => GroupResource::getUrl('edit', ['record' => $subject]),
            $subject instanceof Role && RoleResource::canAccess() => RoleResource::getUrl('edit', ['record' => $subject]),
            $subject instanceof Photo => PhotoResource::detailUrl($subject),
            $subject instanceof Page && $subject->is_published => route('pages.show', $subject),
            $subject instanceof License && LicenseResource::canAccess() => LicenseResource::getUrl('edit', ['record' => $subject]),
            default => null,
        };
    }

    /**
     * Details of a change, one line per field:
     * "Visibility: Private → Public", "Members: +Anna, −Ben", "Password changed".
     *
     * Field names (and values marked "translate", i.e. options and yes/no) are
     * stored in English and translated here; names and other values are shown
     * as recorded.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $properties = $this->properties ?? [];

        if ($this->type === ActivityType::SettingsChanged) {
            return [implode(', ', array_map(fn ($field) => self::settingLabel($field), $properties['fields'] ?? []))];
        }

        if ($this->isBatch()) {
            $names = $properties['names'] ?? [];
            $more = (int) $properties['count'] - count($names);

            return array_values(array_filter([
                $names ? ($more > 0 ? __(':names and :count more', ['names' => implode(', ', $names), 'count' => $more]) : implode(', ', $names)) : null,
                $this->type === ActivityType::Deleted && ($properties['trash'] ?? false) ? __('Can be restored from the trash') : null,
            ]));
        }

        if ($this->type === ActivityType::Deleted && ($properties['trash'] ?? false)) {
            return [__('Moved to the trash, can be restored')];
        }

        if ($this->type === ActivityType::PhotosUploaded && ($properties['failed'] ?? 0) > 0) {
            return [trans_choice(':count file could not be added|:count files could not be added', $properties['failed'])];
        }

        $lines = [];
        foreach ($properties['changes'] ?? [] as $field => $change) {
            $field = self::text($field);
            $value = fn (mixed $value) => $value === null ? __('empty') : (($change['translate'] ?? false) ? self::text($value) : $value);

            $lines[] = match (true) {
                isset($change['hidden']) => __(':field changed', ['field' => $field]),
                array_key_exists('added', $change) => $field.': '.implode(', ', [
                    ...array_map(fn ($name) => '+'.$name, $change['added']),
                    ...array_map(fn ($name) => '−'.$name, $change['removed']),
                ]),
                default => $field.': '.$value($change['from'] ?? null).' → '.$value($change['to'] ?? null),
            };
        }

        return $lines;
    }

    /**
     * One entry of a "settings changed" event, in the current language:
     * a core setting (English label), a plugin's or theme's label as written
     * in its manifest ([label parts]), or a theme's options
     * ({theme, options: [[section, field], ...]}).
     */
    private static function settingLabel(mixed $field): string
    {
        $parts = fn (array $parts) => implode(': ', array_filter(array_map(fn ($part) => (string) Manifest::text($part), $parts)));

        return match (true) {
            is_array($field) && isset($field['theme']) => __('Theme :name', ['name' => Manifest::text($field['theme'])]).': '
                .implode(', ', array_map(fn ($option) => $parts((array) $option), (array) ($field['options'] ?? []))),
            is_array($field) => $parts($field),
            default => self::text($field),
        };
    }

    /**
     * A label stored in English (core text, or a theme / plugin translation
     * key), in the current language.
     */
    private static function text(mixed $text): string
    {
        if (! is_string($text)) {
            return (string) $text;
        }

        return str_contains($text, '::') ? (string) Manifest::text($text) : (string) __($text);
    }

    /** One plain-text sentence, e.g. for the log table and notifications. */
    public function summary(): string
    {
        return trim($this->actorName().' '.$this->action().' '.($this->showsSubject() ? ($this->subjectLabel() ?? '') : ''));
    }

    /** Whether the subject's name follows the action ("changed album Holidays"). */
    public function showsSubject(): bool
    {
        return ! $this->isSignUp() && ! $this->isBatch()
            && ! in_array($this->type, [ActivityType::Login, ActivityType::PasswordReset], true)
            && ! (in_array($this->type, [ActivityType::AccountLinked, ActivityType::AccountUnlinked, ActivityType::SessionsSignedOut], true) && $this->isOwnAccount())
            && ! ($this->type === ActivityType::LoginFailed && ! ($this->properties['known'] ?? false));
    }

    /** Someone acted on their own user account. */
    private function isOwnAccount(): bool
    {
        return $this->subject_type === User::class && $this->user_id !== null && $this->user_id === $this->subject_id;
    }

    /** One entry standing for many items changed by a bulk action. */
    public function isBatch(): bool
    {
        return $this->subject_id === null && isset($this->properties['count'])
            && in_array($this->type, [ActivityType::Created, ActivityType::Updated, ActivityType::Deleted, ActivityType::Restored], true);
    }

    private function isSignUp(): bool
    {
        return $this->type === ActivityType::Created
            && $this->subject_type === User::class
            && ($this->user_id === null || $this->user_id === $this->subject_id);
    }
}
