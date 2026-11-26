<?php

use App\Enums\MailDelivery;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\EmailSettings;
use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(fn () => actingAsRole(Role::Admin, ['email' => 'anna@example.com']));

it('saves SMTP settings with an encrypted password and uses them', function () {
    saveSettingsPage([
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
    $smtp = ['mail_delivery' => MailDelivery::Smtp->value, 'mail_host' => 'smtp.example.org', 'mail_port' => 587, 'mail_username' => 'me'];

    saveSettingsPage([...$smtp, 'mail_password' => 'first']);
    saveSettingsPage($smtp)->assertHasNoFormErrors();

    expect(config('mail.mailers.smtp.password'))->toBe('first');

    saveSettingsPage([...$smtp, 'mail_username' => '']);

    expect(Setting::get(SiteSettings::MAIL_PASSWORD))->toBeNull()
        ->and(config('mail.mailers.smtp.password'))->toBeNull();
});

it('keeps the server configuration by default, signs e-mails with the site name and saves the hourly limit', function () {
    Setting::put(SiteSettings::NAME, 'Scout Troop 7');

    saveSettingsPage(['mail_delivery' => MailDelivery::Server->value, 'mail_hourly_limit' => 75])->assertHasNoFormErrors();

    expect(config('mail.default'))->toBe('array')
        ->and(config('mail.from.name'))->toBe('Scout Troop 7')
        ->and(SiteSettings::mailHourlyLimit())->toBe(75);
});

it('requires a server and a valid sender address for SMTP', function () {
    saveSettingsPage(['mail_delivery' => MailDelivery::Smtp->value, 'mail_host' => '', 'mail_from_address' => 'not-an-address'])
        ->assertHasFormErrors(['mail_host' => 'required', 'mail_from_address' => 'email']);
});

it('sends a test e-mail to the admin with the settings in the form', function () {
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
    Livewire::test(EmailSettings::class)
        ->fillForm(['mail_delivery' => MailDelivery::Smtp->value, 'mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_scheme' => 'smtp'])
        ->call('sendTestMail')
        ->assertNotified('The test e-mail could not be sent');

    expect(config('mail.default'))->toBe('array');
});

it('offers the test mailbox instead of writing mails to the log file, with a warning', function () {
    actingAsRole(Role::SuperAdmin);

    Livewire::test(EmailSettings::class)
        ->assertSee('Test mailbox (nothing is sent)')
        ->assertDontSee('write to the log file')
        ->fillForm(['mail_delivery' => MailDelivery::Mailbox->value])
        ->assertSee('While the test mailbox is on, nobody receives e-mails')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::mailDelivery())->toBe(MailDelivery::Mailbox)
        ->and(config('mail.default'))->toBe('mailbox');
});
