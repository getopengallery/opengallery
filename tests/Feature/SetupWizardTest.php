<?php

use App\Enums\BackgroundJobMode;
use App\Enums\MailDelivery;
use App\Enums\Role;
use App\Enums\UpdateChannel;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Support\Setup\DatabaseSetup;
use App\Support\Setup\EnvironmentFile;
use App\Support\Setup\Installation;
use App\Support\SiteSettings;
use Database\Seeders\DemoSeeder;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** A fresh copy: the wizard has started and the database is migrated, nothing else. */
beforeEach(function () {
    config(['gallery.installed' => null]);
    app()->useEnvironmentPath($this->installationDirectory);
    Installation::begin();
    Installation::flush();
});

/** @return array<string, mixed> session of someone who created (or unlocked with) $admin */
function setupWizardSession(?User $admin = null, array $extra = []): array
{
    return ['setup.started' => true, 'setup.locale' => 'en', ...($admin ? ['setup.admin' => $admin->id] : []), ...$extra];
}

function setupOwner(string $email = 'owner@example.org'): User
{
    return Installation::createSuperAdmin(['name' => 'Owner', 'email' => $email, 'password' => 'a-long-password-1']);
}

it('sends every page to the setup until the installation is finished', function () {
    $this->get('/')->assertRedirect(url('setup'));
    $this->get('/admin')->assertRedirect(url('setup'));
    $this->getJson('/my-albums')->assertStatus(503);
    $this->get(route('setup.welcome'))->assertOk()->assertSee('Server check');
});

it('closes the setup once installed', function () {
    config(['gallery.installed' => true]);

    $this->get(route('setup.welcome'))->assertNotFound();
    $this->get('/')->assertOk();
});

it('starts in the browser\'s language, else in English', function (string $browser, string $expected) {
    // An empty header, because test requests send "en-us" by default.
    $this->withHeader('Accept-Language', $browser)
        ->get(route('setup.welcome'))
        ->assertOk()
        ->assertSee('<html lang="'.$expected.'"', false);
})->with([
    'German browser' => ['de-DE,de;q=0.9,en;q=0.8', 'de'],
    'no language sent' => ['', 'en'],
    'only languages the gallery lacks' => ['fr-FR,fr;q=0.9', 'en'],
]);

it('starts in the chosen language and skips to the first unfinished step', function () {
    $this->post(route('setup.start'), ['locale' => 'de'])
        ->assertRedirect(route('setup.administrator'))
        ->assertSessionHas('setup.locale', 'de');

    $this->withSession(setupWizardSession(extra: ['setup.locale' => 'de']))
        ->get(route('setup.administrator'))
        ->assertOk()
        ->assertSee('Administrator');

    // Steps further on are not reachable yet.
    $this->withSession(setupWizardSession())->get(route('setup.site'))->assertRedirect(route('setup.administrator'));
    $this->flushSession()->get(route('setup.administrator'))->assertRedirect(route('setup.welcome'));
});

it('creates the super administrator with a confirmed address', function () {
    $this->withSession(setupWizardSession())
        ->post(route('setup.administrator'), [
            'name' => 'Owner',
            'email' => 'owner@example.org',
            'password' => 'a-long-password-1',
            'password_confirmation' => 'a-long-password-1',
        ])
        ->assertRedirect(route('setup.site'))
        ->assertSessionHas('setup.admin');

    $owner = User::where('email', 'owner@example.org')->sole();
    expect($owner->hasRole(Role::SuperAdmin->value))->toBeTrue()
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and(Installation::isInstalled())->toBeFalse();
});

it('rejects a short administrator password', function () {
    $this->withSession(setupWizardSession())
        ->post(route('setup.administrator'), [
            'name' => 'Owner',
            'email' => 'owner@example.org',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

it('asks other sessions for the administrator password once one exists', function () {
    setupOwner();

    $this->withSession(setupWizardSession())->get(route('setup.site'))->assertRedirect(route('setup.unlock'));
    $this->withSession(setupWizardSession())->get(route('setup.database'))->assertRedirect(route('setup.unlock'));
    $this->withSession(setupWizardSession())->post(route('setup.finish'), ['content' => 'demo'])->assertRedirect(route('setup.unlock'));

    $this->flushSession()->post(route('setup.unlock'), ['email' => 'owner@example.org', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('setup.admin');

    $this->withSession(setupWizardSession())
        ->post(route('setup.unlock'), ['email' => 'owner@example.org', 'password' => 'a-long-password-1'])
        ->assertRedirect(route('setup.site'))
        ->assertSessionHas('setup.admin');
});

it('saves the site settings, with the SMTP password encrypted', function () {
    $admin = setupOwner();

    $this->withSession(setupWizardSession($admin))
        ->post(route('setup.site'), [
            'name' => 'Family photos',
            'locale' => 'de',
            'public_albums' => '0',
            'registration' => '0',
            'require_approval' => '1',
            'mail_delivery' => MailDelivery::Smtp->value,
            'mail_host' => 'smtp.example.org',
            'mail_port' => '465',
            'mail_scheme' => 'smtps',
            'mail_username' => 'gallery',
            'mail_password' => 'mail-secret',
            'mail_from_address' => 'photos@example.org',
            'background_jobs' => BackgroundJobMode::Cron->value,
            'update_checks' => '1',
            'update_channel' => UpdateChannel::Dev->value,
        ])
        ->assertRedirect(route('setup.demo'))
        ->assertSessionHas('setup.site', true);

    expect(SiteSettings::updateChecksEnabled())->toBeTrue()
        ->and(SiteSettings::updateChannel())->toBe(UpdateChannel::Dev);

    expect(config('app.name'))->toBe('Family photos')
        ->and(config('gallery.default_locale'))->toBe('de')
        ->and(config('gallery.public_albums_enabled'))->toBeFalse()
        ->and(config('gallery.registration_enabled'))->toBeFalse()
        ->and(SiteSettings::requiresApproval())->toBeTrue()
        ->and(SiteSettings::backgroundJobs())->toBe(BackgroundJobMode::Cron)
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.org')
        ->and(Setting::get(SiteSettings::MAIL_PASSWORD))->not->toBe('mail-secret')
        ->and(Crypt::decryptString(Setting::get(SiteSettings::MAIL_PASSWORD)))->toBe('mail-secret');
});

it('needs the SMTP server when SMTP is chosen', function () {
    $admin = setupOwner();

    $this->withSession(setupWizardSession($admin))
        ->post(route('setup.site'), [
            'name' => 'Gallery',
            'locale' => 'en',
            'mail_delivery' => MailDelivery::Smtp->value,
            'background_jobs' => BackgroundJobMode::Ajax->value,
        ])
        ->assertSessionHasErrors(['mail_host', 'mail_port']);
});

it('finishes with an empty gallery and closes the setup', function () {
    $admin = setupOwner();

    $this->withSession(setupWizardSession($admin, ['setup.site' => true]))
        ->post(route('setup.finish'), ['content' => 'empty'])
        ->assertOk()
        ->assertSee('Your gallery is ready')
        ->assertSee($admin->email);

    expect(is_file($this->installationDirectory.'/installed'))->toBeTrue()
        ->and(is_file($this->installationDirectory.'/setup-in-progress'))->toBeFalse()
        ->and(Album::count())->toBe(0);

    Installation::flush();
    $this->get(route('setup.welcome'))->assertNotFound();
});

it('adds the demo content with the new administrator in the admin role', function () {
    Storage::fake('gallery');
    Storage::fake('local');
    DemoSeeder::$photoScale = 0.05;
    $admin = setupOwner();

    $this->withSession(setupWizardSession($admin, ['setup.site' => true]))
        ->post(route('setup.finish'), ['content' => 'demo'])
        ->assertOk()
        ->assertSee('Demo accounts');

    expect(User::where('email', 'admin@example.com')->exists())->toBeFalse()
        ->and(User::where('email', 'mira@example.com')->sole()->hasVerifiedEmail())->toBeTrue()
        ->and(Album::where('title', 'Drafts')->sole()->owner_id)->toBe($admin->id)
        ->and(DemoSeeder::$administrator)->toBeNull()
        ->and(Installation::isInstalled())->toBeTrue();

    DemoSeeder::$photoScale = 1.0;
});

it('sets up an SQLite database and saves it to .env', function () {
    // The step switches connections; leave the shared in-memory test database clean.
    DB::rollBack();
    DatabaseSetup::$sqlitePath = $this->installationDirectory.'/gallery.sqlite';

    try {
        $this->withSession(setupWizardSession())
            ->post(route('setup.database'), ['connection' => 'sqlite'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('setup.administrator'));

        $env = Dotenv::parse(file_get_contents(EnvironmentFile::path()));

        expect($env['DB_CONNECTION'])->toBe('sqlite')
            ->and($env['DB_DATABASE'])->toBe(DatabaseSetup::$sqlitePath)
            ->and($env)->not->toHaveKey('DB_HOST')
            ->and($env['APP_URL'])->toBe(url('/'))
            ->and(DB::connection()->getDatabaseName())->toBe(DatabaseSetup::$sqlitePath)
            ->and(Installation::databaseIsReady())->toBeTrue();
    } finally {
        DatabaseSetup::$sqlitePath = null;
    }
});

it('keeps .env unchanged when the database cannot be reached', function () {
    file_put_contents(EnvironmentFile::path(), "APP_NAME=OpenGallery\n");

    $this->withSession(setupWizardSession())
        ->post(route('setup.database'), [
            'connection' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'gallery',
            'username' => 'gallery',
            'password' => 'secret',
        ])
        ->assertSessionHasErrors('connection');

    expect(file_get_contents(EnvironmentFile::path()))->toBe("APP_NAME=OpenGallery\n");
})->skip(fn () => ! extension_loaded('pdo_mysql'), 'needs pdo_mysql');

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
