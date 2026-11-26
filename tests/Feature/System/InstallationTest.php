<?php

use App\Support\Setup\EnvironmentFile;
use App\Support\Setup\Installation;
use Dotenv\Dotenv;

/** A fresh copy: the wizard has started and the database is migrated, nothing else. */
beforeEach(function () {
    config(['gallery.installed' => null]);
    app()->useEnvironmentPath($this->installationDirectory);
    Installation::begin();
    Installation::flush();
});

it('writes values to .env that read back unchanged', function () {
    file_put_contents(EnvironmentFile::path(), "# comment\nDB_HOST=old\nDB_USERNAME=root\n");

    EnvironmentFile::set([
        'DB_HOST' => 'db.example.org',
        'DB_PASSWORD' => 'p"a\\ss $word #1',
        'DB_USERNAME' => null,
        'APP_URL' => 'https://photos.example.org',
    ]);

    $contents = file_get_contents(EnvironmentFile::path());
    $env = Dotenv::parse($contents);

    expect($contents)->toStartWith("# comment\nDB_HOST=db.example.org\n# DB_USERNAME=root\n")
        ->and($env['DB_PASSWORD'])->toBe('p"a\\ss $word #1')
        ->and($env['APP_URL'])->toBe('https://photos.example.org')
        ->and($env)->not->toHaveKey('DB_USERNAME');
});

it('counts a copy set up before the wizard as installed and writes the marker', function () {
    unlink($this->installationDirectory.'/setup-in-progress');
    Installation::flush();
    expect(Installation::isInstalled())->toBeFalse();

    setupOwner();
    Installation::flush();

    expect(Installation::isInstalled())->toBeTrue()
        ->and(is_file($this->installationDirectory.'/installed'))->toBeTrue();
});

it('keeps the setup closed when a hand-made .env cannot reach its database', function () {
    unlink($this->installationDirectory.'/setup-in-progress');
    config([
        'database.connections.unreachable' => ['driver' => 'sqlite', 'database' => $this->installationDirectory.'/missing/db.sqlite'],
        'database.default' => 'unreachable',
    ]);
    Installation::flush();

    try {
        expect(Installation::isInstalled())->toBeTrue()
            ->and(is_file($this->installationDirectory.'/installed'))->toBeFalse();
    } finally {
        config(['database.default' => 'sqlite']);
    }
});

it('closes the setup when installing on the command line', function () {
    $this->artisan('gallery:install', ['--name' => 'Owner', '--email' => 'cli@example.org', '--password' => 'a-long-password-1'])
        ->assertSuccessful();

    expect(is_file($this->installationDirectory.'/installed'))->toBeTrue()
        ->and(Installation::isInstalled())->toBeTrue();
});
