<?php

namespace App\Support;

use App\Enums\ActivityType;
use App\Enums\CollaboratorRole;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Page;
use App\Models\Photo;
use App\Models\Pivots\AlbumCollaborator;
use App\Models\Pivots\AlbumGroupCollaborator;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Records visits (logins, album views) and administrative changes for the
 * dashboard and the activity log.
 *
 * Privacy: no IP addresses or user agents are stored. Guests are identified
 * by a salted hash that changes every day, which is enough to count unique
 * visitors per day but cannot link one person's visits across days.
 *
 * Changes to the same subject by the same person within MERGE_SECONDS are
 * folded into one entry, so one form save (attributes + relationships) reads
 * as a single line.
 */
class ActivityLog
{
    public const MERGE_SECONDS = 10;

    /** Names listed in a batch entry before "and N more". */
    private const BATCH_NAMES = 3;

    /**
     * Entries collected while a bulk action runs (see batch()), by type and
     * subject class; null when no batch is open.
     *
     * @var array<string, array{first: array<string, mixed>, count: int, names: list<string>}>|null
     */
    private static ?array $batch = null;

    /** Attributes never worth reporting (dashboard_layout: a personal UI preference). */
    private const IGNORED = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'share_token', 'slug', 'guard_name', 'tree_path', 'dashboard_layout'];

    /** Readable nouns for logged subjects. */
    public const NOUNS = [
        Album::class => 'album',
        User::class => 'user',
        Group::class => 'group',
        Role::class => 'role',
        Photo::class => 'photo',
        Page::class => 'page',
    ];

    /** Relationship labels by [parent, related]. */
    private const RELATIONS = [
        Album::class => [User::class => 'shared with users', Group::class => 'shared with groups', Tag::class => 'tags'],
        Group::class => [User::class => 'members', Album::class => 'albums'],
        User::class => [Group::class => 'groups', Role::class => 'roles', Album::class => 'shared albums'],
        Role::class => [Permission::class => 'permissions', User::class => 'users'],
        // Album → Photo is deliberately missing: uploads are logged once per batch.
        Photo::class => [Album::class => 'albums', Tag::class => 'tags'],
    ];

    /** Labels for pivots that link the same two models as another relation, by [pivot, parent]. */
    private const PIVOT_RELATIONS = [
        AlbumCollaborator::class => [Album::class => 'collaborators', User::class => 'collaborating on'],
        AlbumGroupCollaborator::class => [Album::class => 'collaborating groups', Group::class => 'collaborating on'],
    ];

    /** Changes are recorded (any level but "Off"). */
    public static function enabled(): bool
    {
        return SiteSettings::activityLevel()->logsChanges();
    }

    // ---- Visits ------------------------------------------------------------

    public static function login(User $user): void
    {
        if (! SiteSettings::activityLevel()->logsLogins()) {
            return;
        }

        self::write(['type' => ActivityType::Login, 'user_id' => $user->id]);
    }

    public static function albumView(Album $album, Request $request): void
    {
        if (! SiteSettings::activityLevel()->logsVisits()) {
            return;
        }

        $user = $request->user();

        // One view per visitor, album and day is plenty for statistics.
        $key = 'activity.viewed.'.$album->id.'.'.now()->toDateString();
        if ($request->hasSession() && $request->session()->get($key)) {
            return;
        }

        self::write([
            'type' => ActivityType::AlbumView,
            'user_id' => $user?->id,
            'album_id' => $album->id,
            'visitor' => $user ? null : self::visitor($request),
        ]);

        if ($request->hasSession()) {
            $request->session()->put($key, true);
        }
    }

    public static function visitor(Request $request): string
    {
        return hash_hmac('sha256', $request->ip().'|'.$request->userAgent().'|'.now()->toDateString(), (string) config('app.key'));
    }

    // ---- Changes to subjects ----------------------------------------------

    public static function created(Model $subject): void
    {
        self::write(self::subjectAttributes(ActivityType::Created, $subject));
    }

    public static function updated(Model $subject): void
    {
        $changed = array_values(array_diff(array_keys($subject->getChanges()), self::IGNORED));

        // A password reset from the e-mail link is logged as its own security event.
        if ($subject instanceof User && $changed === ['password'] && auth()->guest()) {
            return;
        }

        $changes = [];
        foreach (array_diff(array_keys($subject->getChanges()), self::IGNORED) as $key) {
            $changes[self::fieldLabel($subject, $key)] = in_array($key, $subject->getHidden(), true)
                ? ['hidden' => true]
                : self::change($subject, $key, $subject->getOriginal($key), $subject->getAttribute($key));
        }

        if ($changes !== []) {
            self::mergeOrWrite($subject, $changes);
        }
    }

    public static function deleted(Model $subject): void
    {
        $permanent = method_exists($subject, 'isForceDeleting') && $subject->isForceDeleting();
        $trashable = method_exists($subject, 'isForceDeleting');

        self::write(self::subjectAttributes(ActivityType::Deleted, $subject, ['trash' => $trashable && ! $permanent]));
    }

    public static function restored(Model $subject): void
    {
        self::write(self::subjectAttributes(ActivityType::Restored, $subject));
    }

    /** A link in a pivot table was added or removed (album shares, members, roles, permissions). */
    public static function pivotChanged(Pivot $pivot, bool $added): void
    {
        $parent = $pivot->pivotParent;
        $related = $pivot->pivotRelated;

        if (! $parent instanceof Model || ! $related instanceof Model) {
            return;
        }

        $label = self::PIVOT_RELATIONS[$pivot::class][$parent::class] ?? self::RELATIONS[$parent::class][$related::class] ?? null;
        $relatedId = $pivot->getAttribute($pivot->getRelatedKey());

        if (! $label || $relatedId === null) {
            return;
        }

        $name = self::label($related->newQuery()->find($relatedId)) ?? '#'.$relatedId;

        self::mergeOrWrite($parent, [ucfirst($label) => ['added' => $added ? [$name] : [], 'removed' => $added ? [] : [$name]]]);
    }

    /** A person's or group's role on an album changed: "Anna: Contributor → Editor" on the album. */
    public static function collaboratorRoleChanged(AlbumCollaborator|AlbumGroupCollaborator $link): void
    {
        $album = Album::withTrashed()->find($link->album_id);
        $subject = $link instanceof AlbumCollaborator ? User::find($link->user_id) : Group::find($link->group_id);
        $from = CollaboratorRole::tryFrom((string) $link->getRawOriginal('role'));

        if (! $album || ! $subject || $from === $link->role) {
            return;
        }

        self::mergeOrWrite($album, [self::label($subject) => self::change($album, 'role', $from, $link->role)]);
    }

    public static function photosUploaded(Album $album, int $count, int $failed = 0, ?string $batch = null): void
    {
        if ($batch !== null && self::enabled()) {
            try {
                // Chunked uploads finish one photo per request: fold them into the batch's entry.
                Cache::lock('activity-upload-batch:'.$batch, 10)->block(10, fn () => self::mergeUploadBatch($album, $count, $failed, $batch));

                return;
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($count === 0) {
            return;
        }

        self::write(self::subjectAttributes(ActivityType::PhotosUploaded, $album, ['count' => $count, 'failed' => $failed]));
    }

    /** Adds $count / $failed to the entry of upload batch $batch, or starts it. */
    private static function mergeUploadBatch(Album $album, int $count, int $failed, string $batch): void
    {
        $entry = ActivityEvent::query()
            ->where('type', ActivityType::PhotosUploaded)
            ->where('user_id', auth()->id())
            ->where('subject_type', $album::class)
            ->where('subject_id', $album->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->where('properties->batch', $batch)
            ->latest('id')
            ->first();

        if (! $entry) {
            if ($count > 0) {
                self::write(self::subjectAttributes(ActivityType::PhotosUploaded, $album, ['count' => $count, 'failed' => $failed, 'batch' => $batch]));
            }

            return;
        }

        $properties = $entry->properties;
        $properties['count'] = (int) ($properties['count'] ?? 0) + $count;
        $properties['failed'] = (int) ($properties['failed'] ?? 0) + $failed;
        $entry->update(['properties' => $properties]);
    }

    /** Existing photos added to / removed from an album (one entry per action). */
    public static function photosAdded(Album $album, int $count): void
    {
        if ($count > 0) {
            self::write(self::subjectAttributes(ActivityType::PhotosAdded, $album, ['count' => $count]));
        }
    }

    public static function photosRemoved(Album $album, int $count): void
    {
        if ($count > 0) {
            self::write(self::subjectAttributes(ActivityType::PhotosRemoved, $album, ['count' => $count]));
        }
    }

    // ---- Site-wide administration -----------------------------------------

    /**
     * Labels are stored untranslated and shown in the reader's language
     * (ActivityEvent::details()): English labels of core settings, a
     * plugin's label parts as written in its manifest, or
     * ['theme' => name, 'options' => [[section, field], ...]] from theme.json.
     *
     * @param  list<string|list<string>|array{theme: string, options: list<list<string>>}>  $changedFields
     */
    public static function settingsChanged(array $changedFields): void
    {
        if ($changedFields !== []) {
            self::write(['type' => ActivityType::SettingsChanged, 'user_id' => auth()->id(), 'properties' => ['fields' => $changedFields]]);
        }
    }

    public static function extensionChanged(ActivityType $type, string $name): void
    {
        self::write(['type' => $type, 'user_id' => auth()->id(), 'properties' => ['label' => $name]]);
    }

    // ---- Order -----------------------------------------------------------------

    /** Photos of an album were dragged into a new order (merged like other changes). */
    public static function photosReordered(Album $album): void
    {
        if (! self::enabled()) {
            return;
        }

        $recent = ActivityEvent::query()
            ->where('type', ActivityType::PhotosReordered)
            ->where('subject_type', Album::class)
            ->where('subject_id', $album->getKey())
            ->where('user_id', auth()->id())
            ->where('created_at', '>=', now()->subSeconds(self::MERGE_SECONDS))
            ->exists();

        if (! $recent) {
            self::write(self::subjectAttributes(ActivityType::PhotosReordered, $album));
        }
    }

    /** Albums inside $parent (or the top level) were dragged into a new order. */
    public static function albumsReordered(?Album $parent): void
    {
        $attributes = $parent
            ? self::subjectAttributes(ActivityType::AlbumsReordered, $parent)
            : ['type' => ActivityType::AlbumsReordered, 'user_id' => auth()->id(), 'properties' => []];

        self::write($attributes);
    }

    // ---- Security ---------------------------------------------------------------

    /**
     * A wrong password for an existing account, or an e-mail address without
     * an account (then no subject: the address itself is not stored).
     */
    public static function loginFailed(?User $user, Request $request): void
    {
        self::writeSecurity(ActivityType::LoginFailed, $user, $request, ['known' => $user !== null]);
    }

    public static function lockedOut(?User $user, Request $request): void
    {
        self::writeSecurity(ActivityType::LockedOut, $user, $request);
    }

    public static function passwordReset(User $user): void
    {
        self::writeSecurity(ActivityType::PasswordReset, $user, request(), actor: $user);
    }

    public static function albumPasswordFailed(Album $album, Request $request): void
    {
        self::writeSecurity(ActivityType::AlbumPasswordFailed, $album, $request, actor: $request->user());
    }

    /** A sign-in account (identity provider) was linked to or removed from $user. */
    public static function accountLinked(User $user, string $provider, bool $linked): void
    {
        self::writeSecurity(
            $linked ? ActivityType::AccountLinked : ActivityType::AccountUnlinked,
            $user,
            request(),
            ['provider' => $provider],
            actor: auth()->user() ?? $user,
        );
    }

    /** $count sessions of $user were signed out, by $by (the person themself when null). */
    public static function sessionsSignedOut(User $user, int $count, ?User $by = null): void
    {
        self::writeSecurity(ActivityType::SessionsSignedOut, $user, request(), ['count' => $count], actor: $by ?? $user);
    }

    /** @param array<string, mixed> $properties */
    private static function writeSecurity(ActivityType $type, ?Model $subject, Request $request, array $properties = [], ?User $actor = null): void
    {
        if (! SiteSettings::activityLevel()->logsSecurity()) {
            return;
        }

        self::write([
            ...($subject ? self::subjectAttributes($type, $subject, $properties) : ['type' => $type, 'properties' => $properties]),
            'type' => $type,
            'user_id' => $actor?->getKey(),
            // Lets repeated attempts from one source be grouped for a day, without storing the IP address.
            'visitor' => $actor ? null : self::visitor($request),
        ]);
    }

    // ---- Bulk actions -----------------------------------------------------------

    /**
     * Runs $callback with everything it deletes, restores, creates or changes
     * collected into one entry per kind ("Moved 200 photos to the trash")
     * instead of one entry per item.
     */
    public static function batch(callable $callback): mixed
    {
        if (self::$batch !== null) {
            return $callback();
        }

        self::startBatch();

        try {
            return $callback();
        } finally {
            self::finishBatch();
        }
    }

    public static function startBatch(): void
    {
        self::$batch ??= [];
    }

    public static function finishBatch(): void
    {
        $collected = self::$batch ?? [];
        self::$batch = null;

        foreach ($collected as $group) {
            if ($group['count'] === 1) {
                self::write($group['first']);

                continue;
            }

            self::write([
                'type' => $group['first']['type'],
                'user_id' => $group['first']['user_id'] ?? null,
                'subject_type' => $group['first']['subject_type'],
                'properties' => [
                    'count' => $group['count'],
                    'names' => $group['names'],
                    'trash' => (bool) ($group['first']['properties']['trash'] ?? false),
                ],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private static function collect(array $attributes): bool
    {
        $type = $attributes['type'] ?? null;
        if (self::$batch === null || ! isset($attributes['subject_type'])
            || ! in_array($type, [ActivityType::Created, ActivityType::Updated, ActivityType::Deleted, ActivityType::Restored], true)) {
            return false;
        }

        $key = $type->value.'|'.$attributes['subject_type'].'|'.(int) ($attributes['properties']['trash'] ?? false);
        $group = self::$batch[$key] ?? ['first' => $attributes, 'count' => 0, 'names' => []];
        $group['count']++;

        $name = $attributes['properties']['label'] ?? null;
        if ($name !== null && count($group['names']) < self::BATCH_NAMES) {
            $group['names'][] = (string) $name;
        }

        self::$batch[$key] = $group;

        return true;
    }

    // ---- Helpers -------------------------------------------------------------

    public static function label(?Model $model): ?string
    {
        if (! $model) {
            return null;
        }

        return $model->getAttribute('name') ?? $model->getAttribute('title') ?? '#'.$model->getKey();
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function subjectAttributes(ActivityType $type, Model $subject, array $properties = []): array
    {
        return [
            'type' => $type,
            'user_id' => auth()->id(),
            'album_id' => $subject instanceof Album ? $subject->getKey() : null,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'properties' => ['label' => self::label($subject), ...$properties],
        ];
    }

    /**
     * Fold $changes into a recent Created/Updated entry for the same subject
     * and person, or start a new Updated entry.
     *
     * @param  array<string, array<string, mixed>>  $changes
     */
    private static function mergeOrWrite(Model $subject, array $changes): void
    {
        // Inside a bulk action the items' side effects (e.g. album links of
        // deleted photos) are covered by the batch entry.
        if (! self::enabled() || self::$batch !== null) {
            return;
        }

        $recent = ActivityEvent::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->whereIn('type', [ActivityType::Created, ActivityType::Updated])
            ->where('user_id', auth()->id())
            ->where('created_at', '>=', now()->subSeconds(self::MERGE_SECONDS))
            ->latest('id')
            ->first();

        if (! $recent) {
            self::write([...self::subjectAttributes(ActivityType::Updated, $subject), 'properties' => ['label' => self::label($subject), 'changes' => $changes]]);

            return;
        }

        $properties = $recent->properties ?? [];
        $merged = $properties['changes'] ?? [];

        foreach ($changes as $field => $change) {
            $merged[$field] = self::mergeChange($merged[$field] ?? null, $change);
            if ($merged[$field] === null) {
                unset($merged[$field]);
            }
        }

        // Changes that cancel out (e.g. a member removed and added again) leave nothing to report.
        if ($merged === [] && $recent->type === ActivityType::Updated) {
            $recent->delete();

            return;
        }

        $properties['changes'] = $merged;
        $properties['label'] = self::label($subject);
        $recent->update(['properties' => $properties]);
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>|null null when the changes cancel out
     */
    private static function mergeChange(?array $existing, array $change): ?array
    {
        if ($existing === null) {
            return $change;
        }

        if (array_key_exists('added', $change)) {
            $added = array_merge($existing['added'] ?? [], $change['added']);
            $removed = array_merge($existing['removed'] ?? [], $change['removed']);
            $both = array_intersect($added, $removed);

            $result = ['added' => array_values(array_diff($added, $both)), 'removed' => array_values(array_diff($removed, $both))];

            return $result['added'] === [] && $result['removed'] === [] ? null : $result;
        }

        if (isset($change['hidden'])) {
            return $change;
        }

        $result = ['from' => $existing['from'] ?? null, 'to' => $change['to']];

        if (($existing['translate'] ?? false) || ($change['translate'] ?? false)) {
            $result['translate'] = true;
        }

        return $result['from'] === $result['to'] ? null : $result;
    }

    private static function fieldLabel(Model $subject, string $key): string
    {
        // Stored in English and translated when shown (ActivityEvent::details()).
        if ($key === 'avatar_path') {
            return 'Profile picture';
        }

        if ($key === 'locale') {
            return 'Language';
        }

        if ($key === 'tags_apply_to_sub_albums') {
            return 'Tags apply to sub-albums';
        }

        $name = str_ends_with($key, '_id') ? substr($key, 0, -3) : $key;

        return ucfirst(Str::lower(Str::headline($name)));
    }

    /**
     * One field's change. Option labels and yes / no are stored in English
     * and marked "translate", so they are shown in the reader's language;
     * names, titles and other values are stored as they are.
     *
     * @return array{from: mixed, to: mixed, translate?: true}
     */
    private static function change(Model $subject, string $key, mixed $from, mixed $to): array
    {
        $translator = app('translator');
        $locale = $translator->getLocale();
        $translator->setLocale(Locales::SOURCE);

        try {
            $change = ['from' => self::display($subject, $key, $from), 'to' => self::display($subject, $key, $to)];
        } finally {
            $translator->setLocale($locale);
        }

        $isOption = fn (mixed $value) => $value instanceof HasLabel || is_bool($value);

        return $isOption($from) || $isOption($to) ? [...$change, 'translate' => true] : $change;
    }

    /** Human-readable value: related names for foreign keys, labels for enums. */
    private static function display(Model $subject, string $key, mixed $value): mixed
    {
        if ($key === 'locale' && is_string($value) && $value !== '') {
            return Locales::name($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (str_ends_with($key, '_id')) {
            $relation = Str::camel(substr($key, 0, -3));
            if (method_exists($subject, $relation) && ($rel = $subject->{$relation}()) instanceof BelongsTo) {
                return self::label($rel->getRelated()->newQuery()->find($value)) ?? '#'.$value;
            }
        }

        return match (true) {
            $value instanceof HasLabel => $value->getLabel(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DateFormat::DATE_TIME),
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => Str::limit(trim(strip_tags((string) $value)), 80),
            default => Str::limit((string) json_encode($value), 80),
        };
    }

    /** @param array<string, mixed> $attributes */
    private static function write(array $attributes): void
    {
        $type = $attributes['type'] ?? null;
        $allowed = $type instanceof ActivityType && $type->isSecurity()
            ? SiteSettings::activityLevel()->logsSecurity()
            : self::enabled() || ($type instanceof ActivityType && $type->isVisit());

        if (! $allowed || self::collect($attributes)) {
            return;
        }

        // Logging must never break a page view, login or save.
        try {
            ActivityEvent::create($attributes);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
