<?php

namespace App\Console\Commands;

use App\Support\AppVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Builds the archive people upload to shared hosting, where neither Composer
 * nor Node is available: the last commit (without the files marked
 * `export-ignore` in .gitattributes), production PHP dependencies, Filament's
 * published assets and the built frontend (`public/build`).
 *
 * Needs git, tar, Composer and Node/npm (see .nvmrc) on the machine that builds.
 */
#[Signature('gallery:release
    {version? : Version used in the file name, e.g. v1.2.0 (default: git describe)}
    {--output= : Folder the zip is written to (default: the project folder)}')]
#[Description('Build a release zip for shared hosting, with production dependencies and built assets')]
class BuildRelease extends Command
{
    private const TIMEOUT = 900;

    /**
     * Needed to build the frontend, so they cannot be `export-ignore`, but of
     * no use on the server.
     *
     * @var list<string>
     */
    private const BUILD_ONLY = ['.npmrc', '.nvmrc', 'package.json', 'package-lock.json', 'postcss.config.js', 'tailwind.config.js', 'vite.config.js'];

    public function handle(): int
    {
        try {
            $version = $this->argument('version') ?? (trim($this->shell(['git', 'describe', '--tags', '--always'], base_path())) ?: 'dev');

            if (! preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
                throw new RuntimeException("Invalid version [{$version}]: use letters, digits, dots, dashes and underscores.");
            }

            $this->ensureNodeIsSupported();

            if (trim($this->shell(['git', 'status', '--porcelain'], base_path())) !== '') {
                $this->components->warn('There are uncommitted changes. They are not included: the release is built from the last commit.');
            }
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $output = rtrim($this->option('output') ?? base_path(), '/')."/opengallery-{$version}.zip";
        $work = sys_get_temp_dir().'/opengallery-release-'.Str::lower(Str::random(8));
        $app = "{$work}/opengallery";
        File::ensureDirectoryExists($app);

        try {
            $this->components->task('Exporting the last commit', function () use ($work, $app) {
                $this->shell(['git', 'archive', '--format=tar', "--output={$work}/source.tar", 'HEAD'], base_path());
                $this->shell(['tar', '-xf', "{$work}/source.tar", '-C', $app], base_path());
            });

            $this->components->task('Installing PHP dependencies without dev packages', fn () => $this->shell(
                ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist'], $app,
            ));

            $this->components->task('Building the frontend', function () use ($app, $version) {
                $this->shell(['npm', 'ci'], $app);
                $this->shell(['npm', 'run', 'build'], $app);
                File::deleteDirectory("{$app}/node_modules");
                File::delete(["{$app}/public/hot", ...array_map(fn (string $file) => "{$app}/{$file}", self::BUILD_ONLY)]);
                File::put("{$app}/VERSION", AppVersion::fromTag($version)."\n");
            });

            $this->components->task('Packing '.basename($output), fn () => $this->zip($app, $output));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }

        $this->components->info("Release written to {$output}");

        return self::SUCCESS;
    }

    /**
     * Vite needs Node ^20.19 or >= 22.12 (package.json "engines", .nvmrc); older
     * versions silently skip its native build tool and the build fails late.
     *
     * @throws RuntimeException when Node is missing or too old
     */
    private function ensureNodeIsSupported(): void
    {
        $node = trim($this->shell(['node', '--version'], base_path()));
        [$major, $minor] = array_map('intval', array_slice(explode('.', ltrim($node, 'v')), 0, 2) + [0, 0]);

        if (! (($major === 20 && $minor >= 19) || ($major === 22 && $minor >= 12) || $major >= 23)) {
            throw new RuntimeException("Node {$node} cannot build the frontend: use ^20.19 or >= 22.12 (see .nvmrc).");
        }
    }

    /**
     * @param  list<string>  $command
     *
     * @throws RuntimeException when the command fails
     */
    private function shell(array $command, string $path): string
    {
        $result = Process::path($path)->timeout(self::TIMEOUT)->run($command);

        if ($result->failed()) {
            throw new RuntimeException(implode(' ', $command)." failed:\n".trim($result->errorOutput() ?: $result->output()));
        }

        return $result->output();
    }

    /** Zips $folder with its contents under the top-level folder "opengallery/". */
    private function zip(string $folder, string $output): void
    {
        File::delete($output);
        $zip = new ZipArchive;

        if ($zip->open($output, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Cannot write {$output}.");
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), 'opengallery/'.substr($file->getPathname(), strlen($folder) + 1));
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException("Cannot write {$output}.");
        }
    }
}
