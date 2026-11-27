<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How much the activity log records (Admin → Settings). Each level includes
 * the ones before it.
 */
enum ActivityLevel: string implements HasDescription, HasLabel
{
    case Off = 'off';
    case Changes = 'changes';
    case ChangesAndLogins = 'logins';
    case All = 'all';

    public function getLabel(): string
    {
        return match ($this) {
            self::Off => __('Off'),
            self::Changes => __('Changes only'),
            self::ChangesAndLogins => __('Changes and logins'),
            self::All => __('Everything'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Off => __('Nothing new is recorded. Existing entries stay until they expire.'),
            self::Changes => __('Changes made in the admin (albums, uploads, users, groups, roles, settings) and security events (failed logins, lockouts, password resets, wrong album passwords, linked sign-in accounts).'),
            self::ChangesAndLogins => __('Changes, plus when members log in.'),
            self::All => __('Changes, logins, album views and downloads. Visitors who are not logged in are counted anonymously per day.'),
        };
    }

    public function logsChanges(): bool
    {
        return $this !== self::Off;
    }

    /** Security events are part of every level that records anything. */
    public function logsSecurity(): bool
    {
        return $this !== self::Off;
    }

    public function logsLogins(): bool
    {
        return $this === self::ChangesAndLogins || $this === self::All;
    }

    public function logsVisits(): bool
    {
        return $this === self::All;
    }
}
