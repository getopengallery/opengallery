<?php

use App\Enums\BackgroundJobMode;
use App\Enums\CollaboratorRole;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Clusters\Settings\Pages\EmailSettings;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Livewire\DatabaseNotifications;
use App\Models\Album;
use App\Models\Group;
use App\Models\Setting;
use App\Models\TestMail;
use App\Models\User;
use App\Notifications\GroupAlbumsShared;
use App\Notifications\LoginBlocked;
use App\Support\AlbumCollaborators;
use App\Support\BackgroundJobs;
use App\Support\Dashboard\SiteHealth;
use App\Support\MailQueue;
use App\Support\SecurityNotices;
use App\Support\ShareNotices;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** E-mail that counts as delivered, kept in memory by the mailer. */
function deliverToMemory(): void
{
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'array']]);
    Mail::purge();
}

function mailsSent(): int
{
    return app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages()->count();
}

function mailJobs(): int
{
    return DB::table('jobs')->where('queue', MailQueue::MAIL)->count();
}

/** @return array{0: User, 1: Album} */
function restrictedAlbumBy(string $name = 'Ada'): array
{
    return [User::factory()->create(['name' => $name]), Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Camp'])];
}

/** @param  list<User>  $people */
function shareWith(Album $album, array $people, User $by): void
{
    $album->sharedUsers()->attach(collect($people)->pluck('id')->all());
    ShareNotices::notifyNew($album, [], $by);
}

describe('sending in portions', function () {
    it('tells people in the gallery at once and leaves the e-mail to the background jobs', function () {
        deliverToMemory();
        [$ada, $album] = restrictedAlbumBy();
        $grace = User::factory()->create();

        shareWith($album, [$grace], $ada);

        expect($grace->notifications()->count())->toBe(1)
            ->and(mailsSent())->toBe(0)
            ->and(mailJobs())->toBe(1);

        MailQueue::work();

        expect(mailsSent())->toBe(1)
            ->and(mailJobs())->toBe(0)
            ->and($grace->notifications()->count())->toBe(1);
    });

    it('sends waiting e-mails during a background pass', function () {
        deliverToMemory();
        [$ada, $album] = restrictedAlbumBy();
        shareWith($album, [User::factory()->create()], $ada);

        $this->artisan('gallery:background-jobs')->assertSuccessful();

        expect(mailsSent())->toBe(1);
    });

    it('sends no more per pass than the portion size', function () {
        deliverToMemory();
        [$ada, $album] = restrictedAlbumBy();
        shareWith($album, User::factory()->count(MailQueue::PER_PASS + 1)->create()->all(), $ada);

        MailQueue::work();

        expect(mailsSent())->toBe(MailQueue::PER_PASS)
            ->and(mailJobs())->toBe(1);
    });

    it('stops at the hourly limit and goes on in the next hour', function () {
        deliverToMemory();
        Setting::put(SiteSettings::MAIL_HOURLY_LIMIT, 2);
        [$ada, $album] = restrictedAlbumBy();
        shareWith($album, User::factory()->count(3)->create()->all(), $ada);

        MailQueue::work();
        MailQueue::work();
        expect(mailsSent())->toBe(2);

        $this->travel(61)->minutes();
        MailQueue::work();

        expect(mailsSent())->toBe(3)
            ->and(mailJobs())->toBe(0);
    });

    it('retries a refused e-mail later and keeps the notice in the gallery', function () {
        Mail::extend('refusing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Too many messages');
            }

            public function __toString(): string
            {
                return 'refusing://';
            }
        });
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'refusing']]);
        Mail::purge();
        [$ada, $album] = restrictedAlbumBy();
        $grace = User::factory()->create();
        shareWith($album, [$grace], $ada);

        MailQueue::work();

        $job = DB::table('jobs')->where('queue', MailQueue::MAIL)->sole();
        expect($job->attempts)->toBe(1)
            ->and($job->available_at)->toBeGreaterThan(now()->getTimestamp())
            ->and($grace->notifications()->count())->toBe(1);
    });

    it('keeps e-mails waiting while e-mail does not work', function () {
        deliverToMemory();
        [$ada, $album] = restrictedAlbumBy();
        shareWith($album, [User::factory()->create()], $ada);

        config(['mail.default' => 'log']);
        MailQueue::work();

        expect(mailJobs())->toBe(1);
    });

    it('puts e-mails straight into the test mailbox', function () {
        config(['mail.default' => 'mailbox']);
        [$ada, $album] = restrictedAlbumBy();

        shareWith($album, [User::factory()->create()], $ada);

        expect(TestMail::query()->count())->toBe(1)
            ->and(mailJobs())->toBe(0);
    });

    it('sends a changed-password notice right away', function () {
        deliverToMemory();
        SecurityNotices::passwordChanged(User::factory()->create());

        expect(mailsSent())->toBe(1)
            ->and(mailJobs())->toBe(0);
    });

    it('starts AJAX passes sooner while e-mails wait', function () {
        deliverToMemory();
        BackgroundJobs::run(BackgroundJobMode::Ajax);
        [$ada, $album] = restrictedAlbumBy();

        $this->travel(BackgroundJobs::AJAX_QUEUE_SECONDS + 1)->seconds();
        expect(BackgroundJobs::ajaxDue())->toBeFalse();

        shareWith($album, [User::factory()->create()], $ada);
        expect(BackgroundJobs::ajaxDue())->toBeTrue();
    });

    it('does not report waiting e-mails as stuck thumbnails', function () {
        deliverToMemory();
        config(['gallery.queue_conversions' => true, 'queue.default' => 'database']);
        [$ada, $album] = restrictedAlbumBy();
        shareWith($album, [User::factory()->create()], $ada);
        $this->travel(SiteHealth::STUCK_JOB_MINUTES + 1)->minutes();

        expect(SiteHealth::refresh()['stuckJobs'])->toBe(0);
    });

    it('lets administrators set the hourly limit', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::SuperAdmin->value));

        Livewire::test(EmailSettings::class)
            ->fillForm(['mail_delivery' => 'server', 'mail_hourly_limit' => 75])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(SiteSettings::mailHourlyLimit())->toBe(75);
    });
});

describe('blocked logins', function () {
    it('does the same work during the request for known and unknown addresses', function () {
        Notification::fake();
        $grace = User::factory()->create();
        $attempts = fn (string $email) => collect(range(1, 9))->each(fn () => $this->post('/login', ['email' => $email, 'password' => 'wrong-password']));

        $attempts($grace->email);
        $known = DB::table('jobs')->where('queue', MailQueue::NOTICES)->count();
        $attempts('nobody@example.com');

        expect($known)->toBe(1)
            ->and(DB::table('jobs')->where('queue', MailQueue::NOTICES)->count())->toBe(2);
        Notification::assertNothingSent();

        MailQueue::work();

        Notification::assertSentToTimes($grace, LoginBlocked::class, 1);
    });
});

describe('joining a group', function () {
    /** @return array{0: User, 1: Group, 2: Album} */
    function groupWithAlbum(): array
    {
        $ada = tap(User::factory()->create(['name' => 'Ada']))->assignRole(Role::SuperAdmin->value);
        $group = Group::factory()->create();
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedGroups()->attach($group);
        test()->actingAs($ada);

        return [$ada, $group, $album];
    }

    function addMembers(Group $group, array $people, bool $tell = true): void
    {
        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditGroup::class])
            ->callAction(TestAction::make('attach')->table(), data: [
                'recordId' => collect($people)->pluck('id')->all(),
                'notify_group_access' => $tell,
            ])
            ->assertHasNoFormErrors();
    }

    it('tells new members once, however many albums the group gives them', function () {
        Notification::fake();
        [, $group, $album] = groupWithAlbum();
        Album::factory()->visibility(Visibility::Restricted)->create()->sharedGroups()->attach($group);
        $grace = User::factory()->create();

        addMembers($group, [$grace]);

        Notification::assertSentToTimes($grace, GroupAlbumsShared::class, 1);
        Notification::assertSentTo($grace, GroupAlbumsShared::class, fn (GroupAlbumsShared $notice) => $notice->albums === 2 && $notice->addedBy === 'Ada');
    });

    it('counts albums the group may work on, but not those the person already has', function () {
        Notification::fake();
        [$ada, $group, $album] = groupWithAlbum();
        $grace = User::factory()->create();
        $album->sharedUsers()->attach($grace);
        $worked = Album::factory()->visibility(Visibility::Private)->create();
        AlbumCollaborators::add($worked, ['group:'.$group->id], CollaboratorRole::cases()[0], $ada);

        addMembers($group, [$grace]);

        Notification::assertSentTo($grace, GroupAlbumsShared::class, fn (GroupAlbumsShared $notice) => $notice->albums === 1);
    });

    it('says nothing when the group gives them nothing new', function () {
        Notification::fake();
        [, $group, $album] = groupWithAlbum();
        $album->update(['visibility' => Visibility::Public]);
        $grace = User::factory()->create();

        addMembers($group, [$grace]);

        Notification::assertNotSentTo($grace, GroupAlbumsShared::class);
    });

    it('stays quiet when the switch is off', function () {
        Notification::fake();
        [, $group] = groupWithAlbum();
        $grace = User::factory()->create();

        addMembers($group, [$grace], tell: false);

        Notification::assertNotSentTo($grace, GroupAlbumsShared::class);
        expect($group->users()->whereKey($grace->id)->exists())->toBeTrue();
    });

    it('tells someone from their user form only when the switch is on', function (bool $tell) {
        Notification::fake();
        [, $group] = groupWithAlbum();
        $grace = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
            ->fillForm(['groups' => [$group->id], 'notify_group_access' => $tell])
            ->call('save')
            ->assertHasNoFormErrors();

        $tell
            ? Notification::assertSentTo($grace, GroupAlbumsShared::class)
            : Notification::assertNotSentTo($grace, GroupAlbumsShared::class);
    })->with([true, false]);
});

describe('notices that outlive access', function () {
    it('hides the album title and link once access has ended', function () {
        [$ada, $album] = restrictedAlbumBy();
        $grace = User::factory()->create();
        shareWith($album, [$grace], $ada);

        $this->actingAs($grace)->get(route('notifications.index'))
            ->assertSee('Camp')
            ->assertSee(route('albums.show', $album->slug));

        $album->sharedUsers()->detach($grace);

        $this->get(route('notifications.index'))
            ->assertDontSee('Camp')
            ->assertDontSee(route('albums.show', $album->slug))
            ->assertSee('This album is no longer available to you');
    });

    it('hides them for a deleted album, in the admin too', function () {
        [$ada, $album] = restrictedAlbumBy();
        $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
        shareWith($album, [$admin], $ada);
        $album->delete();
        $this->actingAs($admin);

        Livewire::test(DatabaseNotifications::class)
            ->set('isOpen', true)
            ->assertDontSee('Camp')
            ->assertSee('This album is no longer available to you');
    });

    it('drops a waiting e-mail once access has ended', function () {
        deliverToMemory();
        [$ada, $album] = restrictedAlbumBy();
        $grace = User::factory()->create();
        shareWith($album, [$grace], $ada);

        $album->sharedUsers()->detach($grace);
        MailQueue::work();

        expect(mailsSent())->toBe(0)
            ->and(mailJobs())->toBe(0);
    });

    it('drops a waiting e-mail about a permanently deleted album', function () {
        deliverToMemory();
        [$ada, $album] = restrictedAlbumBy();
        shareWith($album, [User::factory()->create()], $ada);

        $album->forceDelete();
        MailQueue::work();

        expect(mailsSent())->toBe(0)
            ->and(mailJobs())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });
});
