<?php

namespace App\Support\Dashboard;

use App\Extensions\DashboardWidgets;
use App\Filament\Widgets\DashboardWidget;
use App\Models\User;

/**
 * Each person's arrangement of the admin dashboard: order, hidden widgets
 * and sizes, stored in `users.dashboard_layout` as
 * {"v": 1, "widgets": [{"key": "your-albums", "hidden": false, "size": "wide"}, ...]}.
 *
 * The layout always starts from the widgets the signed-in user may see now
 * (registered in DashboardWidgets, canView()), arranged by the preset that
 * fits them best (PRESETS); the saved preferences only order, hide and size
 * those. Saved widgets the person can no longer see (permission removed,
 * plugin turned off) are dropped; widgets that are new to them appear after
 * their default predecessor. Permissions always win.
 */
class DashboardLayout
{
    public const VERSION = 1;

    public const NORMAL = 'normal';

    public const WIDE = 'wide';

    public const FULL = 'full';

    public const SIZES = [self::NORMAL, self::WIDE, self::FULL];

    /** One column on phones, two from medium screens, three on wide ones. Also the album form's grid. */
    public const GRID_COLUMNS = ['default' => 1, 'md' => 2, 'xl' => 3];

    /** Two columns, or the whole row on medium screens. */
    public const WIDE_SPAN = ['default' => 1, 'md' => 2];

    /** One column next to a wide card; the whole row on medium screens, where nothing fits beside it. */
    public const BESIDE_WIDE_SPAN = ['default' => 1, 'md' => 2, 'xl' => 1];

    /**
     * Default arrangements, one per kind of admin user, as widget key =>
     * size. Rows are planned for the three-column grid: what needs the
     * person's attention first, then their own work, then the overview.
     * A person without a saved layout gets the preset that contains the
     * most widgets they may see (on a tie the smallest, i.e. the most
     * specific one), limited to those widgets; widgets the preset does not
     * name (e.g. from plugins) follow in registry order.
     *
     * @var array<string, array<string, string>>
     */
    public const PRESETS = [
        'user-manager' => ['quick-actions' => self::FULL, 'members' => self::FULL],
        'site-operator' => ['quick-actions' => self::FULL, 'site-health' => self::FULL],
        'content-editor' => ['quick-actions' => self::FULL, 'pages' => self::FULL],
        'uploader' => [
            'quick-actions' => self::FULL,
            'your-albums' => self::WIDE, 'recent-uploads' => self::NORMAL,
            'trash' => self::NORMAL,
        ],
        'analyst' => [
            'quick-actions' => self::FULL,
            'activity-trends' => self::FULL,
            'recent-activity' => self::WIDE, 'popular-albums' => self::NORMAL,
            'library' => self::FULL,
            'activity-calendar' => self::FULL,
        ],
        'moderator' => [
            'quick-actions' => self::FULL,
            'albums-attention' => self::NORMAL, 'your-albums' => self::NORMAL, 'recent-uploads' => self::NORMAL,
            'activity-trends' => self::FULL,
            'recent-activity' => self::WIDE, 'popular-albums' => self::NORMAL,
            'library' => self::FULL,
            'activity-calendar' => self::FULL,
            'trash' => self::NORMAL,
        ],
        'administrator' => [
            'quick-actions' => self::FULL,
            'site-health' => self::NORMAL, 'members' => self::NORMAL, 'albums-attention' => self::NORMAL,
            'library' => self::FULL,
            'your-albums' => self::NORMAL, 'recent-uploads' => self::NORMAL, 'pages' => self::NORMAL,
            'activity-trends' => self::FULL,
            'recent-activity' => self::WIDE, 'popular-albums' => self::NORMAL,
            'activity-calendar' => self::FULL,
            'trash' => self::NORMAL,
        ],
    ];

    public function __construct(private DashboardWidgets $widgets) {}

    public static function validSize(mixed $size): ?string
    {
        return in_array($size, self::SIZES, true) ? $size : null;
    }

    /**
     * The widgets the signed-in user may see, in their default order.
     *
     * @return array<string, class-string<DashboardWidget>>
     */
    public function available(): array
    {
        return array_filter($this->widgets->all(), fn (string $widget) => $widget::canView());
    }

    /**
     * The preset that fits widgets $available best: most widgets in common,
     * then fewest widgets in the preset.
     *
     * @param  array<string, class-string<DashboardWidget>>  $available
     */
    public static function presetFor(array $available): string
    {
        $best = null;
        $bestScore = null;

        foreach (self::PRESETS as $name => $preset) {
            $score = [count(array_intersect_key($preset, $available)), -count($preset)];

            if ($bestScore === null || $score > $bestScore) {
                [$best, $bestScore] = [$name, $score];
            }
        }

        return $best;
    }

    /**
     * The default arrangement for widgets $available: the best-fitting
     * preset, then any other widget in registry order with its own size.
     *
     * @param  array<string, class-string<DashboardWidget>>  $available
     * @return array<string, string> key => size, in order
     */
    public static function defaults(array $available): array
    {
        $defaults = array_intersect_key(self::PRESETS[self::presetFor($available)], $available);

        foreach ($available as $key => $widget) {
            $defaults[$key] ??= $widget::defaultSize();
        }

        return $defaults;
    }

    /**
     * The signed-in user's dashboard: every widget they may see, in their
     * order, with their size and whether they hid it.
     *
     * @return list<array{key: string, widget: class-string<DashboardWidget>, size: string, hidden: bool}>
     */
    public function entries(User $user): array
    {
        $available = $this->available();
        $defaults = self::defaults($available);
        $saved = collect($this->saved($user))->keyBy('key');

        $keys = $saved->keys()->filter(fn (string $key) => isset($available[$key]))->values()->all();

        // Widgets not in the saved layout go after the nearest widget before them in the default order.
        $previous = null;
        foreach (array_keys($defaults) as $key) {
            if (! in_array($key, $keys, true)) {
                $position = $previous === null ? 0 : array_search($previous, $keys, true) + 1;
                array_splice($keys, $position, 0, [$key]);
            }
            $previous = $key;
        }

        return array_map(fn (string $key) => [
            'key' => $key,
            'widget' => $available[$key],
            'size' => self::validSize($saved[$key]['size'] ?? null) ?? $defaults[$key],
            'hidden' => (bool) ($saved[$key]['hidden'] ?? false),
        ], $keys);
    }

    /**
     * The entries shown on the dashboard.
     *
     * @return list<array{key: string, widget: class-string<DashboardWidget>, size: string, hidden: bool}>
     */
    public function visible(User $user): array
    {
        return array_values(array_filter($this->entries($user), fn (array $entry) => ! $entry['hidden']));
    }

    /**
     * Saves $entries (in the order given) as $user's layout. Only widgets the
     * signed-in user may see are kept, each once; anything else is ignored.
     * Not logged: it is a personal preference (and ActivityLog ignores the column).
     *
     * @param  iterable<array{key?: mixed, hidden?: mixed, size?: mixed}>  $entries
     */
    public function save(User $user, iterable $entries): void
    {
        $available = $this->available();
        $defaults = self::defaults($available);
        $widgets = [];

        foreach ($entries as $entry) {
            $key = $entry['key'] ?? null;

            if (! is_string($key) || ! isset($available[$key]) || isset($widgets[$key])) {
                continue;
            }

            $widgets[$key] = [
                'key' => $key,
                'hidden' => filter_var($entry['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'size' => self::validSize($entry['size'] ?? null) ?? $defaults[$key],
            ];
        }

        $this->store($user, ['v' => self::VERSION, 'widgets' => array_values($widgets)]);
    }

    /** Back to the default layout. */
    public function reset(User $user): void
    {
        $this->store($user, null);
    }

    /** @return list<array{key: string, hidden?: bool, size?: string}> */
    private function saved(User $user): array
    {
        $layout = $user->dashboard_layout;

        if (! is_array($layout) || ($layout['v'] ?? null) !== self::VERSION || ! is_array($layout['widgets'] ?? null)) {
            return [];
        }

        return array_values(array_filter($layout['widgets'], fn ($entry) => is_array($entry) && is_string($entry['key'] ?? null)));
    }

    /** @param array<string, mixed>|null $layout */
    private function store(User $user, ?array $layout): void
    {
        $user->forceFill(['dashboard_layout' => $layout])->saveQuietly();
    }
}
