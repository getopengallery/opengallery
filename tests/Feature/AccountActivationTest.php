<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\AccessSettings;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountApproved;
use App\Notifications\AccountCreated;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    config(['mail.default' => 'smtp', 'gallery.registration_enabled' => true]);
    Notification::fake();
});

function signUp(string $email = 'grace@example.com'): User
{
    test()->post('/register', [
        'name' => 'Grace',
        'email' => $email,
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
    ]);

    // While e-mail works, signing up does not log in, so the form reveals no existing accounts.
    test()->post('/login', ['email' => $email, 'password' => 'a-long-password']);

    return User::where('email', $email)->sole();
}

function confirmEmail(User $user): void
{
    test()->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]));
}

describe('confirming the e-mail address', function () {
    it('asks people who sign up to confirm their address before they use the gallery', function () {
        $grace = signUp();

        Notification::assertSentTo($grace, VerifyEmail::class);
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->get(route('home'))->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk();
        $this->get(route('profile.edit'))->assertOk();

        confirmEmail($grace);

        $this->get(route('dashboard'))->assertOk();
    });

    it('holds nobody back while e-mail does not work', function () {
        config(['mail.default' => 'log']);

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('dashboard'))
            ->assertOk();
    });

    it('holds the admin area back too', function () {
        $this->actingAs(tap(User::factory()->unverified()->create())->assignRole(Role::Admin->value))
            ->get('/admin')
            ->assertRedirect(route('verification.notice'));
    });

    it('asks to confirm a changed address again', function () {
        $grace = User::factory()->create();

        $this->actingAs($grace)->patch(route('profile.update'), ['name' => $grace->name, 'email' => 'new@example.com', 'current_password' => 'password'])
            ->assertRedirect(route('profile.edit'));

        Notification::assertSentTo($grace, VerifyEmail::class);
        expect($grace->fresh()->email_verified_at)->toBeNull();
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    });

    it('counts people added by an administrator as confirmed', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => false, 'password' => 'a-long-password'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(User::where('email', 'grace@example.com')->sole()->email_verified_at)->not->toBeNull();
    });
});

it('saves a changed "E-mail verified at" on the user page', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
    $grace = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
        ->fillForm(['email_verified_at' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($grace->fresh()->email_verified_at)->toBeNull();
});

describe('approval', function () {
    it('lets people who sign up wait for approval and tells the people who manage users', function () {
        Setting::put(SiteSettings::REQUIRE_APPROVAL, true);
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);

        $grace = signUp();
        confirmEmail($grace);

        expect($grace->fresh()->awaiting_approval)->toBeTrue();
        $this->get(route('dashboard'))->assertRedirect(route('approval.notice'));
        $this->get(route('approval.notice'))->assertOk()->assertSee('Your account is waiting for approval');
        Notification::assertSentTo($admin, AccountCreated::class, fn (AccountCreated $mail) => $mail->account->is($grace));
    });

    it('approves an account from the users list and tells the person', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));
        $grace = tap(User::factory()->create())->forceFill(['awaiting_approval' => true]);
        $grace->save();

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('approve')->table($grace))
            ->assertNotified("{$grace->name} was approved");

        expect($grace->fresh()->awaiting_approval)->toBeFalse();
        Notification::assertSentTo($grace, AccountApproved::class);

        $this->actingAs($grace->fresh())->get(route('dashboard'))->assertOk();
    });

    it('lets people in right away while approval is off', function () {
        $grace = signUp();

        expect($grace->awaiting_approval)->toBeFalse();
    });

    it('turns approval on in the site settings', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

        Livewire::test(AccessSettings::class)
            ->fillForm(['registration' => true, 'require_approval' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(SiteSettings::requiresApproval())->toBeTrue();
    });
});
