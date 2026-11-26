<?php

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Support\ActivityLog;
use Illuminate\Support\Collection;

/** Moves past the merge window, so the next change starts a new entry. */
function afterMergeWindow(): void
{
    test()->travel(ActivityLog::MERGE_SECONDS + 1)->seconds();
}

/**
 * The entries $user changed things with, without logins and album views.
 *
 * @return Collection<int, ActivityEvent>
 */
function changesBy(User $user): Collection
{
    return ActivityEvent::where('user_id', $user->id)->whereNotIn('type', [ActivityType::Login, ActivityType::AlbumView])->get();
}

/**
 * Entries written since the test started (needs the test's "before" id).
 *
 * @return Collection<int, ActivityEvent>
 */
function newEntries(): Collection
{
    return ActivityEvent::where('id', '>', test()->before)->orderBy('id')->get();
}

/** An activity entry old enough to be pruned. */
function staleActivityEvent(): ActivityEvent
{
    return ActivityEvent::factory()->create(['created_at' => now()->subDays(40)]);
}
