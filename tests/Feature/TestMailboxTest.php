<?php

use App\Enums\MailDelivery;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\EmailSettings;
use App\Filament\Pages\TestMailbox;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Setting;
use App\Models\TestMail;
use App\Models\User;
use App\Support\Mailing;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** Admin → Settings → E-mail set to "Test mailbox", applied like at boot. */
function useTestMailbox(): void
{
    Setting::put(SiteSettings::MAIL_DELIVERY, MailDelivery::Mailbox->value);
    SiteSettings::apply();
    Mail::purge();
}

function superAdmin(): User
{
    $superAdmin = tap(User::factory()->create())->assignRole(Role::SuperAdmin->value);
    test()->actingAs($superAdmin);

    return $superAdmin;
}

it('keeps e-mails in the test mailbox instead of sending them, and counts that as working e-mail', function () {
    useTestMailbox();

    Mail::raw('Hello there', fn ($message) => $message->to('grace@example.com', 'Grace')->subject('Welcome'));

    $mail = TestMail::sole();
    expect(Mailing::delivers())->toBeTrue()
        ->and($mail->to)->toContain('grace@example.com')
        ->and($mail->subject)->toBe('Welcome')
        ->and($mail->text)->toBe('Hello there');
});

it('lets invitations and confirmations work end to end without a mail server', function () {
    useTestMailbox();
    superAdmin();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => true])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('The invitation for grace@example.com is in the test mailbox');

    expect(TestMail::sole()->html)->toContain('/invitation/');
});

it('keeps only the newest mails and nothing older than a week', function () {
    foreach (range(1, TestMail::KEEP + 5) as $i) {
        TestMail::create(['to' => "person{$i}@example.com", 'subject' => "Mail {$i}", 'created_at' => now()]);
    }
    TestMail::trim();
    TestMail::create(['to' => 'old@example.com', 'subject' => 'Old', 'created_at' => now()->subDays(8)]);

    $this->artisan('model:prune', ['--model' => [TestMail::class]]);

    expect(TestMail::count())->toBe(TestMail::KEEP)
        ->and(TestMail::where('subject', 'Mail 1')->exists())->toBeFalse()
        ->and(TestMail::where('subject', 'Old')->exists())->toBeFalse();
});

it('shows the mailbox only to super administrators, who can read and empty it', function () {
    useTestMailbox();
    $mail = TestMail::create(['to' => 'grace@example.com', 'subject' => 'Your account', 'html' => '<p>Choose your password</p>', 'created_at' => now()]);

    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value))
        ->get(TestMailbox::getUrl())
        ->assertForbidden();

    superAdmin();
    $this->get(TestMailbox::getUrl())->assertOk()->assertSee('Your account');
    Livewire::test(TestMailbox::class)
        ->mountAction(TestAction::make('open')->table($mail))
        ->assertMountedActionModalSeeHtml('sandbox="allow-popups allow-popups-to-escape-sandbox"')
        ->assertMountedActionModalSee('Choose your password');
    Livewire::test(TestMailbox::class)->callAction(TestAction::make('clear')->table());

    expect(TestMail::count())->toBe(0);
});

it('offers the test mailbox instead of writing mails to the log file, with a warning', function () {
    superAdmin();

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
