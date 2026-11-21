<?php

namespace App\Support;

use App\Models\Photo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Support\MediaStream;
use ZipStream\CompressionMethod;

/**
 * Zip downloads of original photo files, streamed to the browser while the
 * files are read (Spatie's MediaStream on ZipStream): no temporary file and
 * little memory, so large albums work on shared hosting. Photos are stored
 * without compression; JPEGs do not get smaller anyway.
 */
class PhotoZip
{
    /** In-memory mark on a media item whose GPS data stays out of the zip (PhotoZipStream). */
    public const WITHOUT_LOCATION = 'zip_without_location';

    /**
     * @param  iterable<Photo>  $photos  with media loaded
     * @param  (callable(Photo): ?string)|null  $folder  folder inside the zip for each photo
     * @param  (callable(Photo): bool)|null  $withoutLocation  photos that go in without GPS data
     */
    public static function make(string $name, iterable $photos, ?callable $folder = null, ?callable $withoutLocation = null): MediaStream
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $media = Collection::make($photos)
            ->map(function (Photo $photo) use ($folder, $withoutLocation) {
                $original = $photo->original();

                // In memory only (never saved): MediaStream puts the file into this folder.
                if ($original && $folder && filled($path = $folder($photo))) {
                    $original->setCustomProperty('zip_filename_prefix', $path.'/');
                }

                if ($original && $withoutLocation && $withoutLocation($photo)) {
                    $original->setCustomProperty(self::WITHOUT_LOCATION, true);
                }

                return $original;
            })
            ->filter()
            ->values();

        return PhotoZipStream::create((Str::slug($name) ?: 'photos').'.zip')
            ->useZipOptions(function (array &$options) {
                $options['defaultCompressionMethod'] = CompressionMethod::STORE;
            })
            ->addMedia($media);
    }

    /** "Camp › Day 3" becomes the folder "Camp/Day 3" (slashes in titles are replaced). */
    public static function folderFor(string $albumPath): string
    {
        return collect(explode(' › ', $albumPath))
            ->map(fn (string $title) => trim(str_replace(['/', '\\'], '-', $title)) ?: '-')
            ->implode('/');
    }
}
