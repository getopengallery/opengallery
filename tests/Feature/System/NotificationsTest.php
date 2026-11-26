<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Support\AccountApproval;
use App\Support\Inbox;
use App\Support\SiteSettings;
use Illuminate\Notifications\DatabaseNotification;

it('removes notifications older than the retention period', function () {
    $grace = User::factory()->create();
    sharedCamp($grace);
    $this->travel(Inbox::KEEP_DAYS - 1)->days();
    sharedCamp($grace);
    $this->travel(2)->days();

    Inbox::prune();

    expect($grace->notifications()->count())->toBe(1);
});

it('deletes a person\'s notifications, and the notices about their account, with the account', function () {
    $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $grace = User::factory()->create();
    sharedCamp($grace);
    $ada->notify(new AccountCreated($grace));

    $grace->delete();

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('tells people who manage users in the gallery and marks it read once the account is approved', function () {
    config(['gallery.registration_enabled' => true]);
    Setting::put(SiteSettings::REQUIRE_APPROVAL, true);
    $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);

    $this->post('/register', [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
    ]);
    $notice = $ada->notifications()->sole();
    $grace = User::where('email', 'grace@example.com')->sole();

    expect($notice->type)->toBe(AccountCreated::class)
        ->and(Inbox::message($notice)->title)->toBe('Grace is waiting for approval');

    AccountApproval::approve($grace);

    expect($notice->fresh()->read_at)->not->toBeNull();
});
