<?php

namespace App\Models;

use App\Support\UserAgentLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A row of Laravel's `sessions` table: one browser where someone is signed in.
 * `user_agent` holds only a short label (PrivateDatabaseSessionHandler), never
 * the raw user agent; `ip_address` stays empty. Read and deleted through
 * App\Support\UserSessions.
 *
 * @property string $id
 * @property ?int $user_id
 * @property ?string $user_agent
 * @property int $last_activity
 */
class UserSession extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    public function getTable(): string
    {
        return (string) config('session.table', 'sessions');
    }

    public function getConnectionName(): ?string
    {
        return config('session.connection') ?? parent::getConnectionName();
    }

    /**
     * Sessions that have not expired yet, most recently used first.
     *
     * @param  Builder<UserSession>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "Firefox on macOS" in the current language, or "Unknown device". */
    public function deviceLabel(): string
    {
        return UserAgentLabel::display($this->user_agent);
    }

    public function lastActiveAt(): Carbon
    {
        return Carbon::createFromTimestamp($this->last_activity);
    }

    /** The session of the browser making this request. */
    public function isCurrent(): bool
    {
        return request()->hasSession() && hash_equals((string) request()->session()->getId(), (string) $this->id);
    }
}
