<?php

namespace App\Console\Commands;

use App\Support\AppVersion;
use App\Support\Setup\Requirements;
use App\Support\Updates\ReleaseManifest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use ZipArchive;

/**
 * Writes the signed `opengallery-v1.2.0.update.json` next to a release zip.
 * Without it, galleries do not offer the release for one-click install.
 */
#[Signature('gallery:release-sign
    {zip : The release zip}
    {--key-file= : File with the secret key (default: the GALLERY_SIGNING_KEY environment variable)}
    {--requires-from= : Oldest version that may update directly to this one}')]
#[Description('Sign a release zip for one-click updates')]
class SignRelease extends Command
{
    public function handle(): int
    {
        try {
            $path = (string) $this->argument('zip');
            [$version, $files, $unpacked] = $this->inspect($path);
            $channel = ReleaseManifest::channelOf($version);
            $secret = $this->secretKey();
            $configured = ReleaseManifest::publicKey($channel);

            if ($configured !== null && ! hash_equals($configured, sodium_crypto_sign_publickey_from_secretkey($secret))) {
                throw new RuntimeException("This key does not match the configured public key of the {$channel->value} channel.");
            }

            $requiresFrom = $this->option('requires-from') ?: null;

            if ($requiresFrom !== null && ! AppVersion::isRelease($requiresFrom)) {
                throw new RuntimeException("Invalid version [{$requiresFrom}].");
            }

            $manifest = (new ReleaseManifest(
                version: $version,
                channel: $channel,
                file: basename($path),
                size: (int) filesize($path),
                unpackedSize: $unpacked,
                files: $files,
                sha256: (string) hash_file('sha256', $path),
                requiresPhp: Requirements::PHP_VERSION,
                requiresFrom: $requiresFrom,
            ))->signedWith($secret);

            ReleaseManifest::fromArray($manifest->toArray());
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $target = dirname($path)."/opengallery-v{$version}.update.json";
        file_put_contents($target, json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->components->info("Signed for the {$channel->value} channel: {$target}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: int, 2: int} version, number of files, unpacked bytes
     *
     * @throws RuntimeException
     */
    private function inspect(string $path): array
    {
        $zip = new ZipArchive;

        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $version = trim((string) $zip->getFromName('opengallery/VERSION'));
        $files = 0;
        $bytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if (is_array($stat) && ! str_ends_with((string) $stat['name'], '/')) {
                $files++;
                $bytes += (int) $stat['size'];
            }
        }

        $zip->close();

        if (! AppVersion::isRelease($version) || basename($path) !== "opengallery-v{$version}.zip") {
            throw new RuntimeException('The zip name and its VERSION file do not match.');
        }

        return [$version, $files, $bytes];
    }

    /** @throws RuntimeException */
    private function secretKey(): string
    {
        $file = $this->option('key-file');
        $encoded = $file ? (string) @file_get_contents($file) : (string) getenv('GALLERY_SIGNING_KEY');
        $secret = base64_decode(trim($encoded), true);

        if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('No valid secret key. Use --key-file or GALLERY_SIGNING_KEY.');
        }

        return $secret;
    }
}
