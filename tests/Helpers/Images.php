<?php

use App\Support\AlbumPreviewImage;
use App\Support\PhotoLocation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const SECRET_LATITUDE = [52, 1, 31, 1, 1234, 100];

/** A TIFF block as found in EXIF: a camera make and a GPS position. */
function exifWithPosition(bool $littleEndian = true): string
{
    $short = fn (int $value) => pack($littleEndian ? 'v' : 'n', $value);
    $long = fn (int $value) => pack($littleEndian ? 'V' : 'N', $value);
    $entry = fn (int $tag, int $type, int $count, string $value) => $short($tag).$short($type).$long($count).$value;
    $rationals = fn (array $values) => implode('', array_map($long, $values));

    return ($littleEndian ? "II\x2A\0" : "MM\0\x2A").$long(8)
        // First directory: make, pointer to the GPS block.
        .$short(2)
        .$entry(0x010F, 2, 5, $long(38))
        .$entry(0x8825, 4, 1, $long(44))
        .$long(0)
        ."Acme\0\0"
        // GPS block: latitude and longitude with their references.
        .$short(4)
        .$entry(1, 2, 2, "N\0\0\0")
        .$entry(2, 5, 3, $long(98))
        .$entry(3, 2, 2, "E\0\0\0")
        .$entry(4, 5, 3, $long(122))
        .$long(0)
        .$rationals(SECRET_LATITUDE)
        .$rationals([13, 1, 24, 1, 5678, 100]);
}

/** The raw bytes of the latitude in SECRET_LATITUDE, as stored in the EXIF block. */
function secretLatitudeBytes(bool $littleEndian = true): string
{
    return implode('', array_map(fn (int $value) => pack($littleEndian ? 'V' : 'N', $value), SECRET_LATITUDE));
}

/** A tiny generated image in $format ("jpeg", "png" or "webp"). */
function imageBytes(string $format): string
{
    $image = imagecreatetruecolor(32, 24);
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image),
        'png' => imagepng($image),
        'webp' => imagewebp($image),
    };

    return (string) ob_get_clean();
}

/** A JPEG with an EXIF segment holding a GPS position, plus an optional $extraSegment after it. */
function jpegWithPosition(bool $littleEndian = true, string $extraSegment = ''): string
{
    $payload = "Exif\0\0".exifWithPosition($littleEndian);

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload.$extraSegment.substr(imageBytes('jpeg'), 2);
}

/** A PNG chunk of $type with $data and its checksum. */
function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

/** A PNG with $chunk inserted after its header chunk. */
function pngWithChunk(string $chunk): string
{
    $png = imageBytes('png');

    return substr($png, 0, 33).$chunk.substr($png, 33);
}

/** A WebP with an EXIF chunk holding a GPS position. */
function webpWithPosition(): string
{
    $webp = imageBytes('webp').'';
    $exif = exifWithPosition();
    $body = substr($webp, 8).'EXIF'.pack('V', strlen($exif)).$exif;

    return 'RIFF'.pack('V', strlen($body)).$body;
}

/** The bytes of an image after PhotoLocation has removed its location. */
function withoutLocation(string $bytes): string
{
    $source = fopen('php://temp', 'w+b');
    fwrite($source, $bytes);
    rewind($source);
    $result = PhotoLocation::withoutLocation($source);
    $clean = (string) stream_get_contents($result);
    fclose($result);

    return $clean;
}

/** Whether every chunk of $png has a matching checksum. */
function pngChecksumsAreValid(string $png): bool
{
    for ($position = 8; $position + 12 <= strlen($png);) {
        $length = unpack('N', substr($png, $position, 4))[1];
        $stored = unpack('N', substr($png, $position + 8 + $length, 4))[1];

        if (crc32(substr($png, $position + 4, 4 + $length)) !== $stored) {
            return false;
        }

        $position += 12 + $length;
    }

    return true;
}

/**
 * File names inside a streamed zip response, sorted.
 *
 * @return list<string>
 */
function zipEntries(TestResponse $response): array
{
    $path = tempnam(sys_get_temp_dir(), 'og-zip');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i))->sort()->values()->all();
    $zip->close();

    return $names;
}

/** The path of a JPEG of $width x $height pixels stored with EXIF orientation $orientation, as phones save photos taken upright. */
function turnedJpeg(int $width, int $height, int $orientation): string
{
    ob_start();
    imagejpeg(imagecreatetruecolor($width, $height));
    $jpeg = (string) ob_get_clean();
    $exif = "Exif\x00\x00II*\x00".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    file_put_contents($path, "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));

    return $path;
}

/** Asserts the response is a link preview card: a JPEG in the card's size, stored with the given badge. */
function expectPreviewCard(TestResponse $response, string $badge): void
{
    $response->assertOk();
    $size = getimagesizefromstring($response->streamedContent());

    expect([$size[0], $size[1], $size['mime']])->toBe([AlbumPreviewImage::WIDTH, AlbumPreviewImage::HEIGHT, 'image/jpeg'])
        ->and(Storage::disk('local')->files(AlbumPreviewImage::DIRECTORY))->toHaveCount(1)
        ->and(Storage::disk('local')->files(AlbumPreviewImage::DIRECTORY)[0])->toContain("-{$badge}-");
}

/** A $width x $height image of smooth random noise, which does not compress well, so lowering the quality or the size makes the file smaller. */
function noisyImage(int $width, int $height): GdImage
{
    $noise = imagecreatetruecolor(300, 225);

    for ($x = 0; $x < 300; $x++) {
        for ($y = 0; $y < 225; $y++) {
            imagesetpixel($noise, $x, $y, random_int(0, 0xFFFFFF));
        }
    }

    $image = imagecreatetruecolor($width, $height);
    imagecopyresampled($image, $noise, 0, 0, 0, 0, $width, $height, 300, 225);

    return $image;
}

/** The path of a noisy image of $width x $height pixels in $format ("jpeg", "png", "webp" or "gif"), saved at $quality where the format has one. */
function noisyImageFile(int $width, int $height, string $format = 'jpeg', int $quality = 95): string
{
    $image = noisyImage($width, $height);
    $path = tempnam(sys_get_temp_dir(), 'og').'.'.$format;

    match ($format) {
        'jpeg' => imagejpeg($image, $path, $quality),
        'png' => imagepng($image, $path, 1),
        'webp' => imagewebp($image, $path, $quality),
        'gif' => imagegif($image, $path),
    };

    return $path;
}

/** A TIFF block with the camera make "Acme", the orientation, the time taken (2024:05:17 10:30:00) and a GPS position. */
function cameraExif(int $orientation = 1): string
{
    $short = fn (int $value) => pack('v', $value);
    $long = fn (int $value) => pack('V', $value);
    $entry = fn (int $tag, int $type, int $count, string $value) => $short($tag).$short($type).$long($count).$value;
    $rationals = fn (array $values) => implode('', array_map($long, $values));

    return "II*\0".$long(8)
        // First directory (62 bytes after the header): make, orientation, pointers to the EXIF and GPS blocks.
        .$short(4)
        .$entry(0x010F, 2, 5, $long(62))
        .$entry(0x0112, 3, 1, $short($orientation)."\0\0")
        .$entry(0x8769, 4, 1, $long(68))
        .$entry(0x8825, 4, 1, $long(106))
        .$long(0)
        ."Acme\0\0"
        // EXIF block: the time taken.
        .$short(1)
        .$entry(0x9003, 2, 20, $long(86))
        .$long(0)
        ."2024:05:17 10:30:00\0"
        // GPS block: latitude and longitude with their references.
        .$short(4)
        .$entry(1, 2, 2, "N\0\0\0")
        .$entry(2, 5, 3, $long(160))
        .$entry(3, 2, 2, "E\0\0\0")
        .$entry(4, 5, 3, $long(184))
        .$long(0)
        .$rationals([52, 1, 31, 1, 1234, 100])
        .$rationals([13, 1, 24, 1, 5678, 100]);
}

/** The path of a noisy JPEG saved at $quality with the camera data of cameraExif() (orientation $orientation) in its EXIF segment. */
function cameraJpegFile(int $width, int $height, int $orientation = 1, int $quality = 95): string
{
    $jpeg = (string) file_get_contents(noisyImageFile($width, $height, 'jpeg', $quality));
    $payload = "Exif\0\0".cameraExif($orientation);
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    file_put_contents($path, "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2));

    return $path;
}

/** The path of a lossless noisy WebP in the extended layout with the camera data of cameraExif() in an EXIF chunk. */
function cameraWebpFile(int $width, int $height): string
{
    $image = '';
    $bytes = (string) file_get_contents(noisyImageFile($width, $height, 'webp', 100));

    for ($position = 12; $position + 8 <= strlen($bytes);) {
        $length = unpack('V', substr($bytes, $position + 4, 4))[1];
        $total = 8 + $length + $length % 2;

        if (substr($bytes, $position, 4) !== 'VP8X') {
            $image .= substr($bytes, $position, $total);
        }

        $position += $total;
    }

    $exif = cameraExif();
    $header = 'VP8X'.pack('V', 10).chr(0x08)."\0\0\0".substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
    $body = 'WEBP'.$header.$image.'EXIF'.pack('V', strlen($exif)).$exif.(strlen($exif) % 2 ? "\0" : '');
    $path = tempnam(sys_get_temp_dir(), 'og').'.webp';
    file_put_contents($path, 'RIFF'.pack('V', strlen($body)).$body);

    return $path;
}
