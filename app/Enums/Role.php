<?php

namespace App\Enums;

/**
 * Built-in roles seeded by RolesAndPermissionsSeeder. Admins can add custom
 * roles at runtime; these are just the ones the code relies on.
 * "Guest" is not a role: it is the absence of an authenticated user.
 */
enum Role: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Moderator = 'moderator';
    case Uploader = 'uploader';
    case User = 'user';
}
