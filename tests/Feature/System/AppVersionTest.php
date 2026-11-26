<?php

use App\Support\AppVersion;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->versionFile = tempnam(sys_get_temp_dir(), 'og-version-');
    AppVersion::useFile($this->versionFile);
});

afterEach(function () {
    AppVersion::useFile(null);
    File::delete($this->versionFile);
});

it('reads the installed version from the version file', function () {
    File::put($this->versionFile, "1.4.2-dev.7\n");

    expect(AppVersion::current())->toBe('1.4.2-dev.7');
});

it('reports an unknown version when the file is missing or holds no valid version', function (?string $contents) {
    $contents === null ? File::delete($this->versionFile) : File::put($this->versionFile, $contents);

    expect(AppVersion::current())->toBe(AppVersion::UNKNOWN);
})->with(['empty' => '', 'markup' => '<script>alert(1)</script>', 'missing' => null]);
