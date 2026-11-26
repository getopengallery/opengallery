<?php

use Illuminate\Http\UploadedFile;

/**
 * Plugins are loaded while the app boots, so these tests turn the Example
 * plugin on through GALLERY_PLUGINS and rebuild the app.
 */
function bootWithPlugins(string $plugins): void
{
    foreach ([fn ($value) => putenv("GALLERY_PLUGINS={$value}"), fn ($value) => $_ENV['GALLERY_PLUGINS'] = $_SERVER['GALLERY_PLUGINS'] = $value] as $set) {
        $set($plugins);
    }

    test()->refreshApplication();
    test()->restoreInMemoryDatabase();
}

/**
 * Builds a zip file from path => contents; a null content adds a symbolic link.
 *
 * @param  array<string, string|null>  $files
 * @return string the zip's path, in the test's folder (needs the test's "root" folder)
 */
function extensionZipPath(array $files): string
{
    $path = test()->root.'/'.uniqid('zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $file => $contents) {
        $zip->addFromString($file, $contents ?? '/etc/passwd');
        if ($contents === null) {
            $zip->setExternalAttributesName($file, ZipArchive::OPSYS_UNIX, (0120777 << 16));
        }
    }
    $zip->close();

    return $path;
}

/**
 * An uploaded zip made from path => contents.
 *
 * @param  array<string, string|null>  $files
 */
function extensionZip(array $files, string $name = 'package.zip'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(extensionZipPath($files)));
}
