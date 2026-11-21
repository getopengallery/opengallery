<?php

namespace App\Enums;

/**
 * Global (site-wide) permissions. Per-album access is NOT modelled with
 * Spatie permissions; it is derived from Album::$visibility plus explicit
 * shares (album_user / album_group) and evaluated in AlbumPolicy.
 */
enum Permission: string
{
    case AccessAdmin = 'access admin panel';

    case ManageUsers = 'manage users';
    case ManageRoles = 'manage roles';
    case ManageGroups = 'manage groups';
    case ManageSettings = 'manage settings';
    case ManagePages = 'manage pages';
    case ManageThemes = 'manage themes';
    case ManagePlugins = 'manage plugins';
    case InstallExtensions = 'install extensions';
    case ViewActivity = 'view activity';

    case CreateAlbums = 'create albums';
    case UploadPhotos = 'upload photos';
    case ViewAnyAlbum = 'view any album';
    case EditAnyAlbum = 'edit any album';
    case DeleteAnyAlbum = 'delete any album';
    case InviteCollaborators = 'invite collaborators';

    /**
     * Permissions by area, in the order the role form shows them.
     *
     * Keys are English group names, translated where they are shown (__($title)).
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        return [
            'Albums & photos' => [self::CreateAlbums, self::UploadPhotos, self::ViewAnyAlbum, self::EditAnyAlbum, self::DeleteAnyAlbum, self::InviteCollaborators],
            'Admin area' => [self::AccessAdmin, self::ViewActivity],
            'Users & access' => [self::ManageUsers, self::ManageGroups, self::ManageRoles],
            'Site & appearance' => [self::ManageSettings, self::ManagePages, self::ManageThemes, self::ManagePlugins, self::InstallExtensions],
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::AccessAdmin => __('Open the admin area'),
            self::ViewActivity => __('See the dashboard and activity log'),
            self::ManageUsers => __('Manage users'),
            self::ManageGroups => __('Manage groups'),
            self::ManageRoles => __('Manage roles'),
            self::ManageSettings => __('Change site settings'),
            self::ManagePages => __('Edit pages'),
            self::ManageThemes => __('Choose and set up themes'),
            self::ManagePlugins => __('Turn plugins on and off'),
            self::InstallExtensions => __('Upload themes and plugins'),
            self::CreateAlbums => __('Create albums'),
            self::UploadPhotos => __('Upload photos'),
            self::ViewAnyAlbum => __('See every album'),
            self::EditAnyAlbum => __('Edit every album'),
            self::DeleteAnyAlbum => __('Delete every album'),
            self::InviteCollaborators => __('Invite collaborators'),
        };
    }

    public function description(): ?string
    {
        return match ($this) {
            self::AccessAdmin => __('Needed for every other admin permission.'),
            self::CreateAlbums => __('They can edit, share and delete their own albums.'),
            self::UploadPhotos => __('Into albums they may edit.'),
            self::ViewAnyAlbum => __('Including private albums, without a share or password.'),
            self::EditAnyAlbum => __('Also changes the order of albums.'),
            self::InviteCollaborators => __('Owners can invite people and groups to work on their albums. Editors with this permission can also search for people and groups to share an album with.'),
            self::ManageRoles => __('Can give anyone any permission, including this one.'),
            self::ManagePlugins => __('Plugins can add login methods and other features.'),
            self::InstallExtensions => __('Uploaded themes and plugins run code on the server. Give this only to people you trust fully. Needs one of the two permissions above.'),
            default => null,
        };
    }
}
