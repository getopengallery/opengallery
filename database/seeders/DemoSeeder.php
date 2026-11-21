<?php

namespace Database\Seeders;

use App\Actions\AddPhotoToAlbum;
use App\Enums\ActivityType;
use App\Enums\PhotoOrder;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\Avatars;
use App\Support\SiteSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoImages;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo content that shows off every part of OpenGallery:
 *   php artisan db:seed --class=DemoSeeder
 *
 * About 300 generated photos in albums that each demonstrate a feature
 * (visibility levels, shares, password, expiring link, photo order,
 * sub-albums, a "best of" reusing photos, the trash), a cast of users for
 * every role with profile pictures, pages with photo blocks and a year of
 * activity.
 *
 * The year is replayed: the clock is moved back and each change is made as
 * the person who did it, so the change log is written by the real logging
 * code. Visits, logins and security events are added on top.
 *
 * Safe to re-run: users, groups and albums are matched by e-mail or name,
 * photos are only generated for empty albums, and the visit history is only
 * added once.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const ALBUM_PASSWORD = 'demo';

    /** Scales the number of photos (tests and the setup wizard use a fraction). */
    public static float $photoScale = 1.0;

    /**
     * Plays the administrator in the demo instead of admin@example.com (the
     * setup wizard passes the super administrator it just created).
     */
    public static ?User $administrator = null;

    /**
     * The cast: [name, e-mail, role, has a profile picture, joined (days ago)].
     *
     * @var array<string, array{string, string, Role, bool, int}>
     */
    private const PEOPLE = [
        'mira' => ['Mira Okafor', 'mira@example.com', Role::Admin, true, 358],
        'jonas' => ['Jonas Lindqvist', 'jonas@example.com', Role::Moderator, true, 356],
        'priya' => ['Priya Raman', 'priya@example.com', Role::Uploader, true, 340],
        'tomas' => ['Tomás Silva', 'tomas@example.com', Role::User, true, 300],
        'hana' => ['Hana Sato', 'hana@example.com', Role::User, true, 250],
        'leo' => ['Leo Novak', 'leo@example.com', Role::User, false, 200],
        'sofia' => ['Sofia Rossi', 'sofia@example.com', Role::User, false, 120],
    ];

    /** @var array<string, array{string, string, list<string>, int}> name, description, members, created */
    private const GROUPS = [
        'team' => ['Team', 'Everyone who works on the gallery.', ['mira', 'jonas', 'priya'], 355],
        'clients' => ['Clients', 'Customers who review selections before publication.', ['hana', 'sofia'], 120],
    ];

    /**
     * Albums, each showing a feature. Days are "days ago"; photos are taken
     * between `taken` days ago and uploaded on `created` (big albums in two batches).
     *
     * @var array<string, array<string, mixed>>
     */
    private const ALBUMS = [
        'landscapes' => ['title' => 'Landscapes', 'description' => 'Rolling hills, quiet valleys and the light just before sunset.', 'owner' => 'mira', 'visibility' => Visibility::Public, 'order' => PhotoOrder::Manual, 'photos' => 24, 'motif' => 'hills', 'created' => 350, 'taken' => [400, 352]],
        'mountains' => ['title' => 'Mountains', 'description' => 'High ridges and long ascents, from spring to autumn.', 'owner' => 'mira', 'parent' => 'landscapes', 'visibility' => Visibility::Public, 'order' => PhotoOrder::TakenDesc, 'photos' => 34, 'motif' => 'mountains', 'created' => 345, 'taken' => [380, 347]],
        'coast' => ['title' => 'Coastlines', 'description' => 'Cliffs, beaches and harbours.', 'owner' => 'jonas', 'parent' => 'landscapes', 'visibility' => Visibility::Public, 'order' => PhotoOrder::TakenAsc, 'photos' => 26, 'motif' => 'sea', 'created' => 330, 'taken' => [345, 332]],
        'archive' => ['title' => 'Archive 2025', 'description' => 'Last year\'s photos that did not make it into another album. Visible to everyone with an account.', 'owner' => 'mira', 'visibility' => Visibility::Registered, 'order' => PhotoOrder::UploadedDesc, 'photos' => 50, 'motif' => null, 'created' => 320, 'taken' => [700, 370]],
        'forest' => ['title' => 'Forest trails', 'description' => 'Morning walks between tall pines.', 'owner' => 'priya', 'visibility' => Visibility::Public, 'order' => PhotoOrder::Manual, 'photos' => 18, 'motif' => 'forest', 'created' => 300, 'taken' => [310, 302]],
        'city' => ['title' => 'City nights', 'description' => 'Streets, skylines and lit windows after dark.', 'owner' => 'jonas', 'visibility' => Visibility::Public, 'order' => PhotoOrder::Manual, 'photos' => 32, 'motif' => 'city', 'created' => 280, 'taken' => [300, 282]],
        'offsite' => ['title' => 'Team offsite 2026', 'description' => 'Two days of workshops, walks and a very long dinner. Only for the team.', 'owner' => 'mira', 'visibility' => Visibility::Restricted, 'groups' => ['team'], 'order' => PhotoOrder::TakenAsc, 'photos' => 30, 'motif' => 'lights', 'created' => 160, 'taken' => [170, 165]],
        'launch' => ['title' => 'Product launch', 'description' => 'The evening OpenGallery 1.0 was presented.', 'owner' => 'jonas', 'visibility' => Visibility::Registered, 'password' => true, 'order' => PhotoOrder::TakenAsc, 'photos' => 28, 'motif' => 'lights', 'created' => 100, 'taken' => [105, 104]],
        'editorial' => ['title' => 'Editorial picks', 'description' => 'A shortlist for the next print issue, shared with Jonas and Hana.', 'owner' => 'priya', 'visibility' => Visibility::Restricted, 'users' => ['jonas', 'hana'], 'order' => PhotoOrder::Manual, 'photos' => 16, 'motif' => null, 'created' => 60, 'taken' => [200, 62]],
        'drafts' => ['title' => 'Drafts', 'description' => 'Unfinished edits. Only the owner and staff can see this album.', 'owner' => 'admin', 'visibility' => Visibility::Private, 'order' => PhotoOrder::Manual, 'photos' => 12, 'motif' => null, 'created' => 30, 'taken' => [60, 32]],
        'client' => ['title' => 'Client preview: Harbour Hotel', 'description' => 'A selection for the hotel\'s new website. The link works for 30 days.', 'owner' => 'priya', 'visibility' => Visibility::Unlisted, 'expires' => 30, 'order' => PhotoOrder::Manual, 'photos' => 20, 'motif' => 'sea', 'created' => 25, 'taken' => [40, 27]],
        'best' => ['title' => 'Best of 2026', 'description' => 'Favourites from this year, collected from the other albums.', 'owner' => 'admin', 'visibility' => Visibility::Public, 'order' => PhotoOrder::Manual, 'photos' => 0, 'motif' => null, 'created' => 40, 'taken' => [0, 0]],
    ];

    /** Top-level order after the admin dragged the albums into place. */
    private const TOP_LEVEL_ORDER = ['best', 'landscapes', 'city', 'forest', 'launch', 'offsite', 'editorial', 'client', 'archive', 'drafts'];

    private CarbonImmutable $today;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Group> */
    private array $groups = [];

    /** @var array<string, Album> */
    private array $albums = [];

    /** @var list<string> albums that got their photos in this run */
    private array $filled = [];

    private int $seed = 1;

    public function __construct(private AddPhotoToAlbum $add) {}

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $previousNow = Carbon::getTestNow();
        $this->today = CarbonImmutable::now();
        $firstRun = ! ActivityEvent::query()->where('type', ActivityType::Login)->whereIn('user_id', User::whereIn('email', array_column(self::PEOPLE, 1))->select('id'))->exists();

        try {
            $this->seedPeople($firstRun);
            $this->seedAlbums();
            $this->seedLaterChanges();
            $this->seedSystemAlbumsAndPages();

            if ($firstRun) {
                $this->seedAdministration();
                $this->seedSecurity();
                $this->seedVisits();
            }
        } finally {
            $this->actingAs(null);
            Carbon::setTestNow($previousNow);
            CarbonImmutable::setTestNow($previousNow ? CarbonImmutable::instance($previousNow) : null);
        }

        $this->report();
    }

    // ---- People ------------------------------------------------------------

    private function seedPeople(bool $firstRun): void
    {
        $this->at(365, 9);
        $this->users['admin'] = self::$administrator
            ?? User::where('email', 'admin@example.com')->first()
            ?? $this->person('Admin', 'admin@example.com', Role::SuperAdmin);

        foreach (self::PEOPLE as $key => [$name, $email, $role, $hasAvatar, $joined]) {
            $this->at($joined, 10);
            $this->actingAs($this->users['admin']);
            $this->users[$key] = User::where('email', $email)->first()
                ?? $this->person($name, $email, $role);

            if ($hasAvatar && blank($this->users[$key]->avatar_path)) {
                $this->at($joined - 1, 20);
                $this->actingAs($this->users[$key]);
                $this->users[$key]->update(['avatar_path' => Avatars::store(DemoImages::avatar(crc32($email)))]);
            }
        }

        foreach (self::GROUPS as $key => [$name, $description, $members, $created]) {
            $this->at($created, 11);
            $this->actingAs($this->users['admin']);
            $group = Group::firstOrCreate(['name' => $name], ['description' => $description]);
            $group->users()->syncWithoutDetaching(array_map(fn (string $member) => $this->users[$member]->id, $members));
            $this->groups[$key] = $group;
        }

        // Tomás joined the team later and was allowed to upload.
        $this->at(90, 14);
        $this->actingAs($this->users['mira']);
        $this->groups['team']->users()->syncWithoutDetaching([$this->users['tomas']->id]);

        if (! $this->users['tomas']->hasRole(Role::Uploader->value)) {
            $this->at(88, 9);
            $this->users['tomas']->assignRole(Role::Uploader->value);
        }
    }

    /** A demo account with a confirmed address (no factory: release installs have no Faker). */
    private function person(string $name, string $email, Role $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->assignRole($role->value);
    }

    // ---- Albums and photos ----------------------------------------------------

    private function seedAlbums(): void
    {
        foreach (self::ALBUMS as $key => $spec) {
            $owner = $this->users[$spec['owner']];
            $this->at($spec['created'], 9);
            $this->actingAs($owner);

            $parent = isset($spec['parent']) ? $this->albums[$spec['parent']] : null;
            $album = Album::where('title', $spec['title'])->where('parent_id', $parent?->id)->first();

            if (! $album) {
                $album = Album::create([
                    'owner_id' => $owner->id,
                    'parent_id' => $parent?->id,
                    'title' => $spec['title'],
                    'description' => $spec['description'],
                    'visibility' => $spec['visibility'],
                    // City nights starts in manual order and is switched later (see seedLaterChanges()).
                    'photo_order' => $key === 'city' ? PhotoOrder::Manual : $spec['order'],
                    'password' => ($spec['password'] ?? false) ? self::ALBUM_PASSWORD : null,
                    'access_expires_at' => isset($spec['expires']) ? $this->today->addDays($spec['expires'])->endOfDay() : null,
                ]);
                $album->sharedGroups()->attach(array_map(fn (string $group) => $this->groups[$group]->id, $spec['groups'] ?? []));
                $album->sharedUsers()->attach(array_map(fn (string $user) => $this->users[$user]->id, $spec['users'] ?? []));
            }

            $this->albums[$key] = $album;

            if ($spec['photos'] > 0 && ! $album->photos()->exists()) {
                $this->upload($album, $spec, $owner);
                $this->filled[] = $key;
            }
        }
    }

    /** Uploads the album's photos as its owner: one batch, or two for bigger albums. */
    private function upload(Album $album, array $spec, User $owner): void
    {
        $count = $this->scaled($spec['photos']);
        $batches = $count >= 20 ? [(int) ceil($count * 0.6), $count - (int) ceil($count * 0.6)] : [$count];
        [$takenFrom, $takenTo] = $spec['taken'];
        $index = 0;

        foreach ($batches as $batch => $size) {
            $day = $batch === 0 ? $spec['created'] : max(1, $spec['created'] - 12);

            for ($i = 0; $i < $size; $i++, $index++) {
                $this->at($day, 10, $i * 20);
                $this->uploadOne($album, $owner, $spec['motif'] ?? DemoImages::MOTIFS[$this->seed % count(DemoImages::MOTIFS)], $takenFrom - ($takenFrom - $takenTo) * $index / max(1, $count - 1));
            }

            ActivityLog::photosUploaded($album, $size);
        }

        $this->command?->info(sprintf('  %-32s %3d photos', $album->title, $count));
    }

    private function uploadOne(Album $album, User $owner, string $motif, float $takenDaysAgo): Photo
    {
        $seed = $this->seed++;
        $title = DemoImages::title($motif, $seed);
        $photo = $this->add->handle($album, DemoImages::photo($motif, $seed), "{$title}.jpg", $owner);

        mt_srand($seed);
        $hour = match ($motif) {
            'city' => mt_rand(20, 23),
            'lights' => mt_rand(18, 22),
            default => mt_rand(6, 18),
        };
        $photo->updateQuietly([
            'taken_at' => $this->today->subDays((int) round($takenDaysAgo))->setTime($hour, mt_rand(0, 59), mt_rand(0, 59)),
            'exif' => [
                'Make' => 'OpenGallery',
                'Model' => 'Demo Camera',
                'LensModel' => ['24-70mm f/2.8', '35mm f/1.8', '70-200mm f/4'][$seed % 3],
                'FocalLength' => [24, 35, 50, 85, 135][$seed % 5].'/1',
                'FNumber' => [18, 28, 40, 56, 80][$seed % 5].'/10',
                'ExposureTime' => '1/'.[60, 125, 250, 500, 1000][$seed % 5],
                'ISOSpeedRatings' => [100, 200, 400, 800, 1600][$seed % 5],
            ],
        ]);

        return $photo;
    }

    /** Changes made after the albums were filled; only for albums filled in this run. */
    private function seedLaterChanges(): void
    {
        $filled = fn (string $key) => in_array($key, $this->filled, true);

        if ($filled('landscapes')) {
            // Mira dragged a few photos to the front.
            $this->at(300, 16);
            $this->actingAs($this->users['mira']);
            $album = $this->albums['landscapes'];
            $ids = $album->photos()->pluck('photos.id')->all();
            foreach (array_reverse(array_slice($ids, -4)) as $position => $id) {
                DB::table('album_photo')->where('album_id', $album->id)->where('photo_id', $id)->update(['sort_order' => -10 + $position]);
            }
            ActivityLog::photosReordered($album);
        }

        if ($filled('coast')) {
            $this->at(240, 12);
            $this->actingAs($this->users['jonas']);
            $this->albums['coast']->update(['description' => 'Cliffs, beaches and harbours along the northern coast.']);
        }

        if ($filled('city')) {
            $this->at(180, 19);
            $this->actingAs($this->users['jonas']);
            $this->albums['city']->update(['photo_order' => PhotoOrder::TitleAsc]);
        }

        if ($filled('archive')) {
            // A few duplicates went to the trash in one go.
            $this->at(15, 17);
            $this->actingAs($this->users['mira']);
            $photos = $this->albums['archive']->photos()->get();
            ActivityLog::batch(fn () => $photos->take(-min(3, $photos->count() - 1))->each->delete());
        }

        if (! in_array('best', $this->filled, true) && ! $this->albums['best']->photos()->exists()) {
            $this->at(40, 21);
            $this->actingAs($this->users['admin']);
            $candidates = Photo::query()
                ->whereHas('albums', fn ($query) => $query->whereIn('albums.id', collect(['landscapes', 'mountains', 'coast', 'forest', 'city'])->map(fn ($key) => $this->albums[$key]->id)))
                ->orderBy('id')
                ->pluck('id');
            // Spread 14 picks evenly over those albums.
            $step = max(1, intdiv($candidates->count(), 14));
            $picks = $candidates->filter(fn (int $id, int $index) => $index % $step === 0)->take(14)->values();
            $this->albums['best']->addPhotos($picks->all());
            ActivityLog::photosAdded($this->albums['best'], $picks->count());

            // Two favourites were taken out again (they stay in their own albums).
            $removed = $picks->take(-min(2, $picks->count() - 1));
            if ($removed->isNotEmpty()) {
                $this->at(20, 8);
                $this->albums['best']->photos()->detach($removed->all());
                ActivityLog::photosRemoved($this->albums['best'], $removed->count());
            }
            $this->filled[] = 'best';
            $this->command?->info(sprintf('  %-32s %3d photos', $this->albums['best']->title, $this->albums['best']->photos()->count()));
        }

        if (array_intersect($this->filled, self::TOP_LEVEL_ORDER) !== []) {
            $this->at(35, 18);
            $this->actingAs($this->users['admin']);
            foreach (self::TOP_LEVEL_ORDER as $position => $key) {
                $this->albums[$key]->forceFill(['sort_order' => $position + 1])->saveQuietly();
            }
            Album::query()->orderBy('parent_id')->get()->sortBy(fn (Album $album) => $album->depth())->each->refreshTreePath();
            ActivityLog::albumsReordered(null);
        }
    }

    /** "Unsorted uploads" with fresh photos, "Page images" and the pages that use them. */
    private function seedSystemAlbumsAndPages(): void
    {
        $priya = $this->users['priya'];
        $this->at(5, 13);
        $this->actingAs($priya);
        $inbox = Album::inbox($priya);
        if (! $inbox->photos()->exists()) {
            $count = $this->scaled(6);
            for ($i = 0; $i < $count; $i++) {
                $this->at(5, 13, $i * 15);
                $this->uploadOne($inbox, $priya, DemoImages::MOTIFS[$i % count(DemoImages::MOTIFS)], 6);
            }
            ActivityLog::photosUploaded($inbox, $count);
        }

        $admin = $this->users['admin'];
        $this->at(62, 15);
        $this->actingAs($admin);
        $pageImages = Album::pageImages($admin);
        if (! $pageImages->photos()->exists()) {
            foreach (['hills', 'lights', 'mountains'] as $i => $motif) {
                $this->at(62, 15, $i * 10);
                $this->uploadOne($pageImages, $admin, $motif, 70);
            }
            ActivityLog::photosUploaded($pageImages, 3);
        }

        $pageImageIds = $pageImages->photos()->pluck('photos.id');
        $gallery = $this->albums['landscapes']->photos()->limit(4)->pluck('photos.id')->merge($pageImageIds->slice(1))->all();

        $this->at(60, 10);
        $this->call(DemoPagesSeeder::class, true, ['photoId' => $pageImageIds->first(), 'galleryIds' => $gallery]);
    }

    // ---- History (first run only) ---------------------------------------------

    private function seedAdministration(): void
    {
        $this->at(360, 9);
        $this->actingAs($this->users['admin']);

        if (blank(Setting::get(SiteSettings::NAME))) {
            Setting::put(SiteSettings::NAME, 'OpenGallery Demo');
            SiteSettings::apply();
        }
        ActivityLog::settingsChanged(['Site name']);
        ActivityLog::extensionChanged(ActivityType::ThemeActivated, 'Default');

        // The example plugin was tried out for two days.
        $this->at(210, 16);
        ActivityLog::extensionChanged(ActivityType::PluginEnabled, 'Example Plugin');
        $this->at(208, 10);
        ActivityLog::extensionChanged(ActivityType::PluginDisabled, 'Example Plugin');
    }

    /** Failed logins, a lockout, a password reset and a wrong album password. No IPs are stored. */
    private function seedSecurity(): void
    {
        $this->actingAs(null);
        $leo = $this->users['leo'];

        foreach ([0, 2, 4, 6, 7] as $minute) {
            $this->at(199, 7, $minute);
            ActivityLog::loginFailed($leo, $this->request(1));
        }
        ActivityLog::lockedOut($leo, $this->request(1));
        $this->at(199, 8, 30);
        ActivityLog::passwordReset($leo);

        foreach ([3, 3, 9] as $i => $source) {
            $this->at(90 - $i, 2, 10 * $i);
            ActivityLog::loginFailed(null, $this->request($source));
        }

        $this->at(95, 20);
        $request = $this->request(4);
        $request->setUserResolver(fn () => $this->users['sofia']);
        ActivityLog::albumPasswordFailed($this->albums['launch'], $request);
    }

    /**
     * A year of visits: members log in more often at weekends and right after
     * new albums, and guests open the public albums.
     */
    private function seedVisits(): void
    {
        $albums = collect($this->albums)->reject(fn (Album $album) => $album->visibility === Visibility::Private)->values();
        $people = collect($this->users);
        // Who may open which album (as of today; shares do not change in the demo).
        $visibleTo = $people->map(fn (User $person) => $albums->filter(fn (Album $album) => $album->isVisibleTo($person))->pluck('id')->all());
        $rows = [];
        mt_srand(42);

        for ($daysAgo = 364; $daysAgo >= 0; $daysAgo--) {
            $day = $this->today->subDays($daysAgo)->startOfDay();
            $open = $albums->filter(fn (Album $album) => $album->created_at->lte($day->endOfDay()));
            $fresh = $open->contains(fn (Album $album) => $album->created_at->diffInDays($day, true) < 4);
            $busy = ($day->isWeekend() ? 0.4 : 0.15) + ($fresh ? 0.3 : 0);

            foreach ($people as $key => $person) {
                $joined = self::PEOPLE[$key][4] ?? 365;
                $visible = $open->filter(fn (Album $album) => in_array($album->id, $visibleTo[$key], true))->values();

                if ($daysAgo > $joined || $visible->isEmpty() || mt_rand() / mt_getrandmax() >= $busy) {
                    continue;
                }

                $at = $day->addMinutes(mt_rand(7 * 60, 23 * 60));
                $rows[] = $this->visit(ActivityType::Login, $at, $person->id);
                for ($v = mt_rand(1, 3); $v > 0; $v--) {
                    $rows[] = $this->visit(ActivityType::AlbumView, $at->addMinutes(mt_rand(1, 20)), $person->id, $visible->random()->id);
                }
            }

            $public = $open->filter(fn (Album $album) => in_array($album->visibility, [Visibility::Public, Visibility::Unlisted], true))->values();
            if ($public->isNotEmpty()) {
                for ($g = mt_rand(0, $day->isWeekend() ? 6 : 3); $g > 0; $g--) {
                    $rows[] = $this->visit(ActivityType::AlbumView, $day->addMinutes(mt_rand(0, 1439)), null, $public->random()->id, hash('sha256', 'demo|'.$day->toDateString().'|'.$g));
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ActivityEvent::insert($chunk);
        }

        $this->command?->info(sprintf('  %-32s %3d entries', 'Visits', count($rows)));
    }

    /** @return array<string, mixed> */
    private function visit(ActivityType $type, CarbonImmutable $at, ?int $userId, ?int $albumId = null, ?string $visitor = null): array
    {
        return ['type' => $type->value, 'user_id' => $userId, 'album_id' => $albumId, 'visitor' => $visitor, 'created_at' => $at];
    }

    // ---- Helpers ---------------------------------------------------------------

    /** Moves the clock to $daysAgo days before today, at the given time. */
    private function at(int $daysAgo, int $hour, int $minute = 0): void
    {
        $now = $this->today->subDays($daysAgo)->setTime($hour, 0)->addMinutes($minute);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    /** Changes are logged as this person. */
    private function actingAs(?User $user): void
    {
        $user ? Auth::setUser($user) : Auth::forgetUser();
    }

    /** A request from a documentation-range address; only its daily hash is stored. */
    private function request(int $source): Request
    {
        return Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '192.0.2.'.$source, 'HTTP_USER_AGENT' => 'OpenGallery demo']);
    }

    private function scaled(int $count): int
    {
        return max(1, (int) round($count * self::$photoScale));
    }

    private function report(): void
    {
        $client = $this->albums['client']->fresh();

        $this->command?->newLine();
        $this->command?->info('Demo ready. Log in with any of these (password "'.self::PASSWORD.'"):');
        if (self::$administrator === null) {
            $this->command?->line('  admin@example.com (super admin)');
        }
        foreach (self::PEOPLE as [$name, $email, $role]) {
            $this->command?->line("  {$email} ({$name}, ".$role->value.')');
        }
        $this->command?->line('Album password for "'.$this->albums['launch']->title.'": '.self::ALBUM_PASSWORD);
        if ($client?->share_token) {
            $this->command?->line('Share link for "'.$client->title.'": '.route('albums.shared', $client->share_token));
        }
    }
}
