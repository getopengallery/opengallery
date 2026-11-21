<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends a photo file from a private disk (MediaController, PageController).
 * Callers check access first: this only decides how the file is sent.
 *
 * Browsers keep the file for MAX_AGE seconds, never shared caches
 * ("private"). After that they ask again with the ETag and Last-Modified
 * they got, and an unchanged file is answered with "304 Not Modified"
 * instead of being sent again. Both change when the file is regenerated.
 */
class PhotoFileResponse
{
    public const MAX_AGE = 86400;

    /**
     * @param  string|null  $downloadName  save as this name instead of showing the file
     * @param  bool  $withoutLocation  send the file without GPS data (PhotoLocation)
     */
    public static function make(Request $request, FilesystemAdapter $disk, string $path, ?string $downloadName = null, bool $withoutLocation = false): Response
    {
        $modified = (int) $disk->lastModified($path);
        $etag = hash('xxh128', $path.'|'.$disk->size($path).'|'.$modified.($withoutLocation ? '|without-location' : ''));

        $notModified = self::withCacheHeaders(new Response, $etag, $modified);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $response = $withoutLocation
            ? self::streamWithoutLocation($disk, $path, $downloadName)
            : self::viaWebServer($disk, $path, $downloadName)
                ?? ($downloadName !== null ? $disk->download($path, $downloadName) : $disk->response($path));

        return self::withCacheHeaders($response, $etag, $modified);
    }

    /** PHP has to send this one: the web server would send the stored file as it is. */
    private static function streamWithoutLocation(FilesystemAdapter $disk, string $path, ?string $downloadName): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($disk, $path) {
            $stream = PhotoLocation::withoutLocation($disk->readStream($path));
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Content-Length' => (string) $disk->size($path),
        ]);

        if ($downloadName !== null) {
            $fallback = str_replace(['/', '\\', '%'], '', Str::ascii($downloadName));
            $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', $downloadName, $fallback));
        }

        return $response;
    }

    /**
     * With GALLERY_SENDFILE set, the web server sends the file instead of
     * PHP (X-Sendfile for Apache's mod_xsendfile, X-Accel-Redirect for
     * nginx), so the PHP worker is free as soon as access is checked. Files
     * stay private: the header only names the file to the web server.
     */
    private static function viaWebServer(FilesystemAdapter $disk, string $path, ?string $downloadName): ?Response
    {
        $mode = config('gallery.sendfile');
        $file = realpath($disk->path($path));
        $storage = realpath(storage_path());

        if (! in_array($mode, ['apache', 'nginx'], true) || ! $file || ! $storage || ! str_starts_with($file, $storage.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $response = new Response('', 200, ['Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream']);

        if ($mode === 'apache') {
            $response->headers->set('X-Sendfile', $file);
        } else {
            // An internal nginx location that maps this prefix to the storage folder (see the installation guide).
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($storage) + 1));
            $response->headers->set('X-Accel-Redirect', rtrim((string) config('gallery.sendfile_nginx_prefix'), '/').'/'.$relative);
        }

        if ($downloadName !== null) {
            $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', $downloadName, 'download'));
        }

        return $response;
    }

    private static function withCacheHeaders(Response $response, string $etag, int $modified): Response
    {
        return $response
            ->setEtag($etag)
            ->setLastModified(new DateTimeImmutable('@'.$modified))
            ->setPrivate()
            ->setMaxAge(self::MAX_AGE);
    }
}
