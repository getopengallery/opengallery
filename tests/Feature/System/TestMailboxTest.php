<?php

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\TestMail;
use App\Support\Mailing;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

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
    actingAsRole(Role::SuperAdmin);

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
