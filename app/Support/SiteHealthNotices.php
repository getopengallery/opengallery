<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Setting;
use App\Notifications\SiteProblemFound;
use App\Support\Dashboard\SiteHealth;

/**
 * Tells people who change site settings about new site health problems
 * (SiteProblemFound), right after the background jobs repeat the checks
 * (BackgroundJobs::tasks()). Only problems and warnings count, notes do not.
 *
 * A problem is told once, when it appears; it is told again only after it was
 * gone in between. The very first check only remembers what it found: those
 * problems are on the dashboard already, and an update would otherwise send
 * a burst of notices about things everyone knows.
 */
class SiteHealthNotices
{
    /** Setting: keys of the problems found by the last check. */
    public const SEEN = 'notices.site-health';

    /** @return int how many new problems were told */
    public static function check(): int
    {
        $problems = array_values(array_filter(
            SiteHealth::report()['problems'],
            fn (array $problem) => $problem['severity'] !== SiteHealth::INFO,
        ));
        $current = array_column($problems, 'key');
        $previous = Setting::get(self::SEEN);

        if ($previous !== $current) {
            Setting::put(self::SEEN, $current);
        }

        if (! is_array($previous)) {
            return 0;
        }

        // Fixed problems: their notices are dealt with.
        foreach (array_diff($previous, $current) as $fixed) {
            Inbox::markHandled(SiteProblemFound::class, 'key', $fixed);
        }

        $new = array_filter($problems, fn (array $problem) => ! in_array($problem['key'], $previous, true));

        if ($new === []) {
            return 0;
        }

        $people = Inbox::peopleWho(Permission::ManageSettings);

        foreach ($new as $problem) {
            foreach ($people as $person) {
                Mailing::send(fn () => $person->notify(new SiteProblemFound($problem)));
            }
        }

        return count($new);
    }
}
