<?php

use App\Enums\BackgroundJobMode;
use App\Models\Setting;
use App\Models\TestMail;
use App\Models\User;
use App\Notifications\LoginBlocked;
use App\Support\BackgroundJobs;
use App\Support\Dashboard\SiteHealth;
use App\Support\Mailing;
use App\Support\MailQueue;
use App\Support\SecurityNotices;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

describe('sending', function () {
    it('only counts mail that leaves the server as delivered', function () {
        config(['mail.default' => 'log']);
        expect(Mailing::delivers())->toBeFalse();

        config(['mail.default' => 'smtp']);
        expect(Mailing::delivers())->toBeTrue();
    });

    it('reports a failing mail server instead of throwing', function () {
        expect(Mailing::send(fn () => throw new RuntimeException('Connection refused')))->toBeFalse()
            ->and(Mailing::send(fn () => null))->toBeTrue();
    });
});

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

describe('notices that outlive access', function () {
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
