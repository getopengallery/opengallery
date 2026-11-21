<?php

namespace App\Enums;

enum ActivityType: string
{
    case Login = 'login';
    case AlbumView = 'album_view';

    /** Changes to a subject (album, user, group, role). */
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case PhotosUploaded = 'photos_uploaded';
    case PhotosAdded = 'photos_added';
    case PhotosRemoved = 'photos_removed';

    /** Site-wide administration. */
    case SettingsChanged = 'settings_changed';
    case ThemeActivated = 'theme_activated';
    case PluginEnabled = 'plugin_enabled';
    case PluginDisabled = 'plugin_disabled';
    case ThemeInstalled = 'theme_installed';
    case PluginInstalled = 'plugin_installed';

    /** Order changes (drag and drop), one entry per album / level. */
    case PhotosReordered = 'photos_reordered';
    case AlbumsReordered = 'albums_reordered';

    /** Security: recorded at every level except "Off". */
    case LoginFailed = 'login_failed';
    case LockedOut = 'locked_out';
    case PasswordReset = 'password_reset';
    case AlbumPasswordFailed = 'album_password_failed';
    case AccountLinked = 'account_linked';
    case AccountUnlinked = 'account_unlinked';

    public function label(): string
    {
        return match ($this) {
            self::Login => __('Logged in'),
            self::AlbumView => __('Opened album'),
            self::Created => __('Created'),
            self::Updated => __('Changed'),
            self::Deleted => __('Deleted'),
            self::Restored => __('Restored'),
            self::PhotosUploaded => __('Uploaded photos'),
            self::PhotosAdded => __('Added photos'),
            self::PhotosRemoved => __('Removed photos'),
            self::SettingsChanged => __('Changed settings'),
            self::ThemeActivated => __('Activated theme'),
            self::PluginEnabled => __('Turned plugin on'),
            self::PluginDisabled => __('Turned plugin off'),
            self::ThemeInstalled => __('Uploaded theme'),
            self::PluginInstalled => __('Uploaded plugin'),
            self::PhotosReordered => __('Reordered photos'),
            self::AlbumsReordered => __('Reordered albums'),
            self::LoginFailed => __('Failed login'),
            self::LockedOut => __('Locked out'),
            self::PasswordReset => __('Reset password'),
            self::AlbumPasswordFailed => __('Wrong album password'),
            self::AccountLinked => __('Linked sign-in account'),
            self::AccountUnlinked => __('Unlinked sign-in account'),
        };
    }

    /** Security events: failed or blocked sign-ins, password resets, linked accounts. */
    public function isSecurity(): bool
    {
        return in_array($this, self::security(), true);
    }

    /** @return list<self> */
    public static function security(): array
    {
        return [self::LoginFailed, self::LockedOut, self::PasswordReset, self::AlbumPasswordFailed, self::AccountLinked, self::AccountUnlinked];
    }

    /** Visits, as opposed to administrative changes. */
    public function isVisit(): bool
    {
        return in_array($this, [self::Login, self::AlbumView], true);
    }
}
