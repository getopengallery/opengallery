<?php

namespace App\Models;

use Database\Factories\AlbumGuestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone without an account a restricted album is shared with by e-mail
 * (App\Support\AlbumGuests). Their link carries a token whose hash is
 * stored here; opening it sends them a 6-digit code.
 */
#[Fillable(['album_id', 'email', 'token', 'invited_by'])]
#[Hidden(['token', 'code'])]
class AlbumGuest extends Model
{
    /** @use HasFactory<AlbumGuestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'code_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'code_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Changes with the token, so a removed guest or a new link ends earlier sessions. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->id.'|'.$this->token), 0, 16);
    }
}
