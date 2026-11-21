<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Fakes git, tar, Composer and npm: tar "exports" an app file, npm builds a
 * manifest and leaves node_modules behind, as the real tools would.
 *
 * @param  array<string, mixed>  $results  command prefix => fake result
 */
function fakeReleaseTools(array $results = []): void
{
    test()->exportedTo = null;

    Process::fake(function (PendingProcess $process) use ($results) {
        $command = implode(' ', (array) $process->command);

        foreach ($results as $prefix => $result) {
            if (str_starts_with($command, $prefix)) {
                return $result;
            }
        }

        if (str_starts_with($command, 'tar ')) {
            $target = $process->command[array_search('-C', $process->command, true) + 1];
            test()->exportedTo = $target;
            File::put("{$target}/artisan", '<?php');
            File::put("{$target}/VERSION", "1.3.0-dev\n");
            File::put("{$target}/package.json", '{}');
            File::put("{$target}/vite.config.js", '');
        }

        if ($command === 'npm ci') {
            File::ensureDirectoryExists("{$process->path}/node_modules/vite");
        }

        if ($command === 'npm run build') {
            File::ensureDirectoryExists("{$process->path}/public/build");
            File::put("{$process->path}/public/build/manifest.json", '{}');
        }

        return Process::result(output: match ($command) {
            'node --version' => 'v22.12.0',
            default => '',
        });
    });
}

it('builds a zip with production dependencies and built assets but without node_modules', function () {
    fakeReleaseTools();
    $output = sys_get_temp_dir().'/og-release-test-'.uniqid();
    File::ensureDirectoryExists($output);

    $this->artisan('gallery:release', ['version' => 'v1.2.0', '--output' => $output])->assertSuccessful();

    $zip = new ZipArchive;
    $zip->open("{$output}/opengallery-v1.2.0.zip");
    $entries = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i))->sort()->values()->all();
    $stampedVersion = $zip->getFromName('opengallery/VERSION');
    $zip->close();

    expect($entries)->toBe(['opengallery/VERSION', 'opengallery/artisan', 'opengallery/public/build/manifest.json'])
        ->and($stampedVersion)->toBe("1.2.0\n")
        ->and(File::exists($this->exportedTo))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist']
        && $process->path === $this->exportedTo);

    File::deleteDirectory($output);
});

it('refuses to build with a Node version Vite cannot use', function () {
    fakeReleaseTools(['node --version' => Process::result(output: 'v20.17.0')]);

    $this->artisan('gallery:release', ['version' => 'v1.2.0'])
        ->expectsOutputToContain('Node v20.17.0 cannot build the frontend')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'composer');
});

it('stops at a failing step, reports it and removes the work folder', function () {
    fakeReleaseTools(['npm ci' => Process::result(errorOutput: 'network down', exitCode: 1)]);

    $this->artisan('gallery:release', ['version' => 'v1.2.0'])
        ->expectsOutputToContain('npm ci failed')
        ->assertFailed();

    expect(File::exists($this->exportedTo))->toBeFalse()
        ->and(File::exists(base_path('opengallery-v1.2.0.zip')))->toBeFalse();
});

it('rejects version names that are not safe in a file name', function () {
    fakeReleaseTools();

    $this->artisan('gallery:release', ['version' => '../evil'])->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'git' && ($process->command[1] ?? null) === 'archive');
});
