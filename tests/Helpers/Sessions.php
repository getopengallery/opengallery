<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stores sessions in the database, as the "where you're signed in" lists need. */
function useDatabaseSessions(): void
{
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
}

/**
 * A session row of another browser of $user, as the session handler stores it.
 *
 * @param  ?string  $label  device label, as UserAgentLabel makes it (null for none)
 * @param  ?int  $lastActivity  unix time of the last request (now when null)
 */
function sessionRow(User $user, string $id, ?string $label = 'Firefox on macOS', ?int $lastActivity = null): string
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => null,
        'user_agent' => $label,
        'payload' => '',
        'last_activity' => $lastActivity ?? now()->getTimestamp(),
    ]);

    return $id;
}

/**
 * The ids of the session rows left, optionally only those of $user.
 *
 * @return list<string>
 */
function sessionIds(?User $user = null): array
{
    return DB::table('sessions')
        ->when($user, fn ($query) => $query->where('user_id', $user->id))
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

/**
 * Makes the next requests of the test come from the browser with session $id (a new
 * browser when null; the session handler starts afresh, as in a new PHP process); the test client sends no cookies on its own. Returns the id.
 */
function fromBrowser(?string $id = null): string
{
    $id ??= Str::random(40);
    test()->withCookie(config('session.cookie'), $id);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app('redirect')->setSession(app('session.store'));

    return $id;
}
