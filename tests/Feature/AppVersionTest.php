<?php

use App\Enums\Role;
use App\Models\User;
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

it('reports an unknown version when the file holds no valid version', function (string $contents) {
    File::put($this->versionFile, $contents);

    expect(AppVersion::current())->toBe(AppVersion::UNKNOWN);
})->with(['empty' => '', 'markup' => '<script>alert(1)</script>']);

it('reports an unknown version when the file is missing', function () {
    File::delete($this->versionFile);

    expect(AppVersion::current())->toBe(AppVersion::UNKNOWN);
});

it('shows the version in the admin only to people who manage settings', function (Role $role, bool $visible) {
    File::put($this->versionFile, '7.7.7');
    $this->actingAs(User::factory()->create()->assignRole($role->value));

    $response = $this->get('/admin')->assertOk();

    $visible ? $response->assertSee('Version 7.7.7') : $response->assertDontSee('Version 7.7.7');
})->with([
    'administrator' => [Role::Admin, true],
    'uploader' => [Role::Uploader, false],
]);
