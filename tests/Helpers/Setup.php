<?php

use App\Models\User;
use App\Support\Setup\Installation;

/**
 * The session of someone who created (or unlocked with) $admin.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function setupWizardSession(?User $admin = null, array $extra = []): array
{
    return ['setup.started' => true, 'setup.locale' => 'en', ...($admin ? ['setup.admin' => $admin->id] : []), ...$extra];
}

/** Creates the installation's super admin "Owner" through the installer. */
function setupOwner(string $email = 'owner@example.org'): User
{
    return Installation::createSuperAdmin(['name' => 'Owner', 'email' => $email, 'password' => 'a-long-password-1']);
}
