<?php

use App\Enums\MailDelivery;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\EmailSettings;
use App\Filament\Clusters\Settings\Pages\PhotoSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\PhotoUploads;
use App\Support\SiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function mailSettingsAdmin(): User
{
    $admin = tap(User::factory()->create(['email' => 'anna@example.com']))->assignRole(Role::Admin->value);
    test()->actingAs($admin);

    return $admin;
}

/**
 * @param  array<string, mixed>  $data
 * @param  class-string  $page
 */
function saveSettings(array $data, string $page = EmailSettings::class): mixed
{
    return Livewire::test($page)->fillForm($data)->call('save');
}

describe('e-mail', function () {
    it('saves SMTP settings with an encrypted password and uses them', function () {
        mailSettingsAdmin();

        saveSettings([
            'mail_delivery' => MailDelivery::Smtp->value,
            'mail_host' => 'smtp.example.org',
            'mail_port' => 465,
            'mail_scheme' => 'smtps',
            'mail_username' => 'gallery@example.org',
            'mail_password' => 'secret-pass',
            'mail_from_address' => 'photos@example.org',
        ])->assertHasNoFormErrors();

        $stored = Setting::get(SiteSettings::MAIL_PASSWORD);
        expect($stored)->not->toBe('secret-pass')
            ->and(Crypt::decryptString($stored))->toBe('secret-pass')
            ->and(config('mail.default'))->toBe('smtp')
            ->and(config('mail.mailers.smtp'))->toMatchArray([
                'host' => 'smtp.example.org',
                'port' => 465,
                'scheme' => 'smtps',
                'username' => 'gallery@example.org',
                'password' => 'secret-pass',
            ])
            ->and(config('mail.from.address'))->toBe('photos@example.org');
    });

    it('keeps the saved password when the field is left empty and drops it with the user name', function () {
        mailSettingsAdmin();
        $smtp = ['mail_delivery' => MailDelivery::Smtp->value, 'mail_host' => 'smtp.example.org', 'mail_port' => 587, 'mail_username' => 'me'];

        saveSettings([...$smtp, 'mail_password' => 'first']);
        saveSettings($smtp)->assertHasNoFormErrors();

        expect(config('mail.mailers.smtp.password'))->toBe('first');

        saveSettings([...$smtp, 'mail_username' => '']);

        expect(Setting::get(SiteSettings::MAIL_PASSWORD))->toBeNull()
            ->and(config('mail.mailers.smtp.password'))->toBeNull();
    });

    it('keeps the server configuration by default and signs e-mails with the site name', function () {
        mailSettingsAdmin();

        Setting::put(SiteSettings::NAME, 'Scout Troop 7');

        saveSettings(['mail_delivery' => MailDelivery::Server->value])->assertHasNoFormErrors();

        expect(config('mail.default'))->toBe('array')
            ->and(config('mail.from.name'))->toBe('Scout Troop 7');
    });

    it('requires a server and a valid sender address for SMTP', function () {
        mailSettingsAdmin();

        saveSettings(['mail_delivery' => MailDelivery::Smtp->value, 'mail_host' => '', 'mail_from_address' => 'not-an-address'])
            ->assertHasFormErrors(['mail_host' => 'required', 'mail_from_address' => 'email']);
    });

    it('sends a test e-mail to the admin with the settings in the form', function () {
        mailSettingsAdmin();
        Event::fake([MessageSent::class]);

        Livewire::test(EmailSettings::class)
            ->fillForm(['mail_delivery' => MailDelivery::Server->value, 'mail_from_name' => 'Test sender'])
            ->call('sendTestMail')
            ->assertNotified('Test e-mail sent to anna@example.com');

        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->getTo()[0]->getAddress() === 'anna@example.com'
            && $event->message->getFrom()[0]->getName() === 'Test sender');
        expect(Setting::get(SiteSettings::MAIL_FROM_NAME))->toBeNull();
    });

    it('reports why a test e-mail could not be sent and keeps the saved settings', function () {
        mailSettingsAdmin();

        Livewire::test(EmailSettings::class)
            ->fillForm(['mail_delivery' => MailDelivery::Smtp->value, 'mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_scheme' => 'smtp'])
            ->call('sendTestMail')
            ->assertNotified('The test e-mail could not be sent');

        expect(config('mail.default'))->toBe('array');
    });
});

describe('uploads', function () {
    beforeEach(function () {
        Storage::fake('local');
        config(['media-library.image_driver' => 'gd']);
    });

    it('saves the largest photo size and the accepted file types and enforces them', function () {
        mailSettingsAdmin();

        saveSettings(['upload_max_mb' => 5, 'upload_types' => ['image/jpeg', 'image/png']], PhotoSettings::class)->assertHasNoFormErrors();

        expect(Setting::get(SiteSettings::UPLOAD_TYPES))->toBe(['image/jpeg', 'image/png'])
            ->and(PhotoUploads::maxBytes())->toBe(5 * 1024 * 1024)
            ->and(config('media-library.max_file_size'))->toBe(5 * 1024 * 1024)
            ->and(PhotoUploads::acceptedExtensions())->toBe(['jpg', 'jpeg', 'jfif', 'png']);

        $this->postJson(route('uploads.photos'), [
            'upload' => (string) Str::uuid(),
            'batch' => (string) Str::uuid(),
            'target' => 'inbox',
            'name' => 'animation.gif',
            'size' => 3,
            'offset' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'abc'),
        ])->assertJsonValidationErrors(['name']);
    });

    it('refuses file types this server cannot process', function () {
        mailSettingsAdmin();

        expect(PhotoUploads::processableTypes())->not->toContain('image/heic');

        saveSettings(['upload_types' => ['image/jpeg', 'image/heic']], PhotoSettings::class)->assertHasFormErrors(['upload_types.1']);

        expect(Setting::get(SiteSettings::UPLOAD_TYPES))->toBeNull();
    });

    it('needs at least one file type and a sensible size', function () {
        mailSettingsAdmin();

        saveSettings(['upload_max_mb' => 0, 'upload_types' => []], PhotoSettings::class)
            ->assertHasFormErrors(['upload_max_mb' => 'min', 'upload_types' => 'required']);
    });
});
