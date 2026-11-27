<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Spatie\MediaLibrary\Support\MediaStream;
use ZipStream\ZipStream;

/**
 * Spatie's zip stream, but files marked with PhotoZip::WITHOUT_LOCATION go
 * into the zip without their GPS data (App\Support\PhotoLocation).
 */
class PhotoZipStream extends MediaStream
{
    public function getZipStream(bool $finish = true): ZipStream
    {
        $this->zipOptions['outputName'] = $this->zipName;
        $zip = new ZipStream(...$this->zipOptions);

        $this->getZipStreamContents()->each(function (array $mediaInZip) use ($zip) {
            $stream = $mediaInZip['media']->stream();

            if ($mediaInZip['media']->getCustomProperty(PhotoZip::WITHOUT_LOCATION)) {
                $stream = PhotoLocation::withoutLocation($stream);
            }

            $zip->addFileFromStream($mediaInZip['fileNameInZip'], $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        });

        if ($finish) {
            $zip->finish();
        }

        return $zip;
    }

    /** @var array<string, int> how often each name was used in its folder */
    private array $usedNames = [];

    /**
     * Name of the file inside the zip: its PhotoZip::FILE_NAME if set, else
     * the stored name; repeated names get " (1)", " (2)" etc.
     */
    protected function getFileNameWithSuffix(Collection $mediaItems, int $currentIndex): string
    {
        $media = $mediaItems[$currentIndex];
        $fileName = $media->getCustomProperty(PhotoZip::FILE_NAME) ?? $media->getDownloadFilename();
        $key = mb_strtolower($this->getZipFileNamePrefix($mediaItems, $currentIndex).$fileName);

        $count = $this->usedNames[$key] ?? 0;
        $this->usedNames[$key] = $count + 1;

        if ($count === 0) {
            return $fileName;
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $base = pathinfo($fileName, PATHINFO_FILENAME);

        return $extension !== '' ? "{$base} ({$count}).{$extension}" : "{$base} ({$count})";
    }
}
