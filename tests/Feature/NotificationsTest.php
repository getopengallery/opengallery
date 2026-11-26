<?php

use App\Enums\ActivityLevel;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Livewire\DatabaseNotifications;
use App\Models\Album;
use App\Models\Setting;
use App\Models\TestMail;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Notifications\AlbumShared;
use App\Notifications\QueuedMailChannel;
use App\Notifications\SiteProblemFound;
use App\Support\AccountApproval;
use App\Support\Inbox;
use App\Support\SiteHealthNotices;
use App\Support\SiteSettings;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

describe('in the gallery', function () {
    it('lists notifications on the site in the reader\'s language and marks the shown ones as read', function () {
        $grace = User::factory()->create(['locale' => 'de']);
        sharedCamp($grace);

        $this->actingAs($grace)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Ada hat „Camp“ mit Ihnen geteilt');

        expect($grace->unreadNotifications()->count())->toBe(0);
    });

    it('shows the unread count on the bell in the site header', function () {
        $grace = User::factory()->create();
        sharedCamp($grace);
        sharedCamp($grace);

        $this->actingAs($grace)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Notifications, 2 unread');
    });

    it('sends guests to the login page', function () {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    });

    it('shows notifications in the admin panel with the gallery\'s date format', function () {
        $this->travelTo('2026-11-21 10:30:00');
        $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);
        sharedCamp($ada);
        $this->actingAs($ada);

        Livewire::test(DatabaseNotifications::class)
            ->set('isOpen', true)
            ->assertSee('Ada shared')
            ->assertSee('21.11.2026 10:30')
            ->assertSee('Open');
    });

    it('falls back to the stored title when the notification\'s class is gone', function () {
        $grace = User::factory()->create();
        $grace->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'Plugins\\Removed\\Notice',
            'data' => ['format' => 'filament', 'title' => 'A note from a removed plugin'],
        ]);

        $this->actingAs($grace)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('A note from a removed plugin');
    });

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
});

describe('album shares', function () {
    it('tells people in the gallery when e-mail is not set up', function () {
        $ada = tap(User::factory()->create(['name' => 'Ada']))->assignRole(Role::Admin->value);
        $grace = User::factory()->create();
        $album = Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Camp']);
        $this->actingAs($ada);

        Livewire::test(EditAlbum::class, ['record' => $album->getRouteKey()])
            ->fillForm(['shared_with' => ['user:'.$grace->id], 'notify_shared' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('1 person was told in the gallery');

        expect($grace->notifications()->sole())
            ->type->toBe(AlbumShared::class)
            ->data->toMatchArray(['data' => ['album' => $album->id, 'slug' => $album->slug, 'title' => 'Camp', 'by' => 'Ada']]);
    });
});

describe('new accounts', function () {
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
});

describe('site health', function () {
    beforeEach(function () {
        config(['gallery.activity_log_level' => ActivityLevel::All->value, 'mail.default' => 'smtp']);
    });

    it('only remembers the problems of the very first check', function () {
        $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);
        config(['mail.default' => 'mailbox']);

        expect(SiteHealthNotices::check())->toBe(0)
            ->and($ada->notifications()->count())->toBe(0);
    });

    it('tells people who change settings once about a new problem and marks it read when it is fixed', function () {
        $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);
        $moderator = tap(User::factory()->create())->assignRole(Role::Moderator->value);
        SiteHealthNotices::check();

        config(['mail.default' => 'mailbox']);
        SiteHealthNotices::check();
        SiteHealthNotices::check();
        $notice = $ada->notifications()->sole();

        expect($notice->type)->toBe(SiteProblemFound::class)
            ->and(Inbox::message($notice)->title)->toBe('E-mails are not sent')
            ->and($moderator->notifications()->count())->toBe(0)
            ->and(TestMail::query()->count())->toBe(0);

        config(['mail.default' => 'smtp']);
        SiteHealthNotices::check();

        expect($notice->fresh()->read_at)->not->toBeNull();
    });

    it('also e-mails problems that stop the site from working', function () {
        Notification::fake();
        $ada = tap(User::factory()->create())->assignRole(Role::Admin->value);
        SiteHealthNotices::check();

        config(['app.debug' => true]);
        $this->app['env'] = 'production';
        SiteHealthNotices::check();

        Notification::assertSentTo($ada, SiteProblemFound::class, fn (SiteProblemFound $notice, array $channels) => $notice->problem['key'] === 'debug-mode' && $channels === ['database', QueuedMailChannel::class]);
    });
});
