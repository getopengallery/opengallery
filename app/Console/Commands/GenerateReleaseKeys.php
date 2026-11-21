<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Creates the key pairs that sign releases. The secret keys go into a folder
 * outside the project; the public keys are printed for `config/gallery.php`.
 */
#[Signature('gallery:release-keys
    {--output= : Folder for the secret keys, outside the project (default: ~/.opengallery-signing)}')]
#[Description('Create the signing keys for stable and dev releases')]
class GenerateReleaseKeys extends Command
{
    public function handle(): int
    {
        $folder = rtrim((string) ($this->option('output') ?: (getenv('HOME') ?: '').'/.opengallery-signing'), '/');

        if ($folder === '/.opengallery-signing' || $folder === '') {
            $this->components->error('Choose a folder with --output.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($folder, 0700);
        $real = (string) realpath($folder);
        $base = (string) realpath(base_path());

        if ($real === $base || str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
            $this->components->error('The secret keys must not be stored inside the project.');

            return self::FAILURE;
        }

        foreach (['stable', 'dev'] as $channel) {
            if (file_exists("{$real}/{$channel}.key")) {
                $this->components->error("{$real}/{$channel}.key already exists. Keys are never overwritten.");

                return self::FAILURE;
            }
        }

        $public = [];

        foreach (['stable', 'dev'] as $channel) {
            $pair = sodium_crypto_sign_keypair();
            $file = "{$real}/{$channel}.key";
            touch($file);
            chmod($file, 0600);
            file_put_contents($file, base64_encode(sodium_crypto_sign_secretkey($pair))."\n");
            $public[$channel] = base64_encode(sodium_crypto_sign_publickey($pair));
        }

        $this->components->info("Secret keys written to {$real}. Keep them private and back them up.");
        $this->line('Put the public keys into config/gallery.php under update_keys:');
        $this->newLine();
        $this->line("'stable' => env('GALLERY_UPDATE_KEY_STABLE', '{$public['stable']}'),");
        $this->line("'dev' => env('GALLERY_UPDATE_KEY_DEV', '{$public['dev']}'),");

        return self::SUCCESS;
    }
}
