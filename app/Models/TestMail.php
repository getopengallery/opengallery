<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * An e-mail kept in the test mailbox instead of being sent (mail delivery
 * "Test mailbox", App\Support\MailboxTransport). Holds working password
 * and invitation links, so only super administrators may read it
 * (App\Filament\Pages\TestMailbox). Only the newest KEEP mails of the last
 * KEEP_DAYS days are kept.
 */
#[Fillable(['from', 'to', 'subject', 'html', 'text', 'created_at'])]
class TestMail extends Model
{
    use MassPrunable;

    public const KEEP = 100;

    public const KEEP_DAYS = 7;

    public const UPDATED_AT = null;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** Drops everything but the newest KEEP mails (called after each new one). */
    public static function trim(): void
    {
        $oldestKept = static::query()->latest('id')->skip(self::KEEP - 1)->value('id');

        if ($oldestKept !== null) {
            static::query()->where('id', '<', $oldestKept)->delete();
        }
    }
}
