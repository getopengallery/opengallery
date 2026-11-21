<?php

namespace App\Support\Updates;

use App\Enums\UpdateChannel;
use App\Support\AppVersion;

/**
 * The signed description of a release (`opengallery-v1.2.0.update.json`).
 *
 * The signature covers the version, channel and checksum, so an old release
 * cannot be passed off as a new one and a test version cannot pass as stable.
 * Stable and dev releases have separate keys (`config('gallery.update_keys')`).
 */
final readonly class ReleaseManifest
{
    public const FORMAT = 1;

    private const MAX_SIZE = 1024 ** 3;

    private const MAX_UNPACKED = 3 * 1024 ** 3;

    private const MAX_FILES = 100000;

    public function __construct(
        public string $version,
        public UpdateChannel $channel,
        public string $file,
        public int $size,
        public int $unpackedSize,
        public int $files,
        public string $sha256,
        public string $requiresPhp,
        public ?string $requiresFrom = null,
        public string $signature = '',
    ) {}

    /** @throws UpdateFailed when the data is not a valid manifest */
    public static function fromArray(mixed $data): self
    {
        $invalid = new UpdateFailed(__('The release information is not valid.'));

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw $invalid;
        }

        foreach (['version', 'channel', 'file', 'sha256', 'requires_php', 'signature'] as $field) {
            if (! is_string($data[$field] ?? null)) {
                throw $invalid;
            }
        }

        foreach (['size', 'unpacked_size', 'files'] as $field) {
            if (! is_int($data[$field] ?? null) || $data[$field] < 1) {
                throw $invalid;
            }
        }

        $requiresFrom = $data['requires_from'] ?? null;
        $channel = UpdateChannel::tryFrom($data['channel']);

        if ($channel === null
            || ! AppVersion::isRelease($data['version'])
            || $channel !== self::channelOf($data['version'])
            || $data['file'] !== "opengallery-v{$data['version']}.zip"
            || preg_match('/^[a-f0-9]{64}$/', $data['sha256']) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/', $data['requires_php']) !== 1
            || ($requiresFrom !== null && (! is_string($requiresFrom) || ! AppVersion::isRelease($requiresFrom)))
            || $data['size'] > self::MAX_SIZE
            || $data['unpacked_size'] > self::MAX_UNPACKED
            || $data['files'] > self::MAX_FILES) {
            throw $invalid;
        }

        return new self(
            version: $data['version'],
            channel: $channel,
            file: $data['file'],
            size: $data['size'],
            unpackedSize: $data['unpacked_size'],
            files: $data['files'],
            sha256: $data['sha256'],
            requiresPhp: $data['requires_php'],
            requiresFrom: $requiresFrom,
            signature: $data['signature'],
        );
    }

    /** Versions with a suffix are test versions and belong to the dev channel. */
    public static function channelOf(string $version): UpdateChannel
    {
        return str_contains($version, '-') ? UpdateChannel::Dev : UpdateChannel::Stable;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => $this->version,
            'channel' => $this->channel->value,
            'file' => $this->file,
            'size' => $this->size,
            'unpacked_size' => $this->unpackedSize,
            'files' => $this->files,
            'sha256' => $this->sha256,
            'requires_php' => $this->requiresPhp,
            'requires_from' => $this->requiresFrom,
            'signature' => $this->signature,
        ];
    }

    /** A copy signed with a raw Ed25519 secret key. */
    public function signedWith(string $secretKey): self
    {
        return new self(
            $this->version, $this->channel, $this->file, $this->size, $this->unpackedSize, $this->files,
            $this->sha256, $this->requiresPhp, $this->requiresFrom,
            base64_encode(sodium_crypto_sign_detached($this->message(), $secretKey)),
        );
    }

    /**
     * Checks the signature with the key of the release's channel. A site on
     * the stable channel accepts stable releases only.
     *
     * @throws UpdateFailed
     */
    public function verify(UpdateChannel $siteChannel): void
    {
        $untrusted = new UpdateFailed(__('The signature of this release could not be verified, so it was not installed.'));

        if ($siteChannel === UpdateChannel::Stable && $this->channel !== UpdateChannel::Stable) {
            throw $untrusted;
        }

        $key = self::publicKey($this->channel);
        $signature = base64_decode($this->signature, true);

        if ($key === null || ! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $this->message(), $key)) {
            throw $untrusted;
        }
    }

    /** The raw public key that signs releases of $channel, if one is configured. */
    public static function publicKey(UpdateChannel $channel): ?string
    {
        $key = base64_decode((string) config("gallery.update_keys.{$channel->value}"), true);

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    private function message(): string
    {
        return implode("\n", [
            'opengallery-update', self::FORMAT, $this->version, $this->channel->value, $this->file,
            $this->size, $this->unpackedSize, $this->files, $this->sha256, $this->requiresPhp, $this->requiresFrom ?? '',
        ]);
    }
}
