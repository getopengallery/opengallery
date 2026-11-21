<?php

namespace App\Support;

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
}
