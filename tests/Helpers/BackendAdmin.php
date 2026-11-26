<?php

use App\Extensions\PluginManager;
use App\Extensions\ThemeManager;
use Illuminate\Support\Facades\File;

/**
 * Points the theme and plugin managers at a throwaway folder (with a copy of the
 * Default theme), so uploads install there and never into the real /themes and /plugins.
 * Needs afterEach(fn () => adminRemoveExtensionSandbox()).
 */
function adminUseExtensionSandbox(): void
{
    $root = storage_path('framework/testing/extensions-'.uniqid());
    File::ensureDirectoryExists($root.'/themes');
    File::ensureDirectoryExists($root.'/plugins');
    File::copyDirectory(base_path('themes/default'), $root.'/themes/default');

    // extensionZipPath() builds its zip files in the test's "root" folder.
    test()->root = $root;
    app()->instance(ThemeManager::class, new ThemeManager($root.'/themes', 'default'));
    app()->instance(PluginManager::class, new PluginManager(app(), $root.'/plugins'));
}

/** Removes the folder adminUseExtensionSandbox() made. */
function adminRemoveExtensionSandbox(): void
{
    File::deleteDirectory(test()->root);
}
