<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Notifications\AccountCreated;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['mail.default' => 'smtp', 'gallery.registration_enabled' => true]);
    Notification::fake();
});

it('holds people who sign up back until an administrator approves them, and tells the people who manage users', function () {
    Setting::put(SiteSettings::REQUIRE_APPROVAL, true);
    $admin = userWithRole(Role::Admin);

    $grace = signUp();
    confirmEmail($grace);

    expect($grace->fresh()->awaiting_approval)->toBeTrue();
    $this->get(route('dashboard'))->assertRedirect(route('approval.notice'));
    $this->get(route('approval.notice'))->assertOk()->assertSee('Your account is waiting for approval');
    Notification::assertSentTo($admin, AccountCreated::class, fn (AccountCreated $mail) => $mail->account->is($grace));
});

it('lets people in right away while approval is off', function () {
    $grace = signUp();
    confirmEmail($grace);

    expect($grace->fresh()->awaiting_approval)->toBeFalse();
    $this->get(route('dashboard'))->assertOk();
});
