<?php

namespace App\Models;

use App\Support\ActivityLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['provider', 'provider_user_id', 'email', 'avatar', 'token', 'refresh_token', 'token_expires_at'])]
#[Hidden(['token', 'refresh_token'])]
class SocialAccount extends Model
{
    /** Linking and unlinking sign-in accounts are security events. */
    protected static function booted(): void
    {
        static::created(fn (SocialAccount $account) => $account->user && ActivityLog::accountLinked($account->user, $account->provider, linked: true));
        static::deleted(fn (SocialAccount $account) => $account->user && ActivityLog::accountLinked($account->user, $account->provider, linked: false));
    }

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
