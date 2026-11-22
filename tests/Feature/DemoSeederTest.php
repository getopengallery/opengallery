<?php

use App\Enums\ActivityType;
use App\Enums\Visibility;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Page;
use App\Models\Photo;
use App\Models\User;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('gallery');
    Storage::fake('local');
    DemoSeeder::$photoScale = 0.05;
});

afterEach(fn () => DemoSeeder::$photoScale = 1.0);

it('seeds a demo with every feature, written by the real activity log and backdated', function () {
    $this->seed(DemoSeeder::class);

    expect(User::whereNotNull('avatar_path')->count())->toBe(5)
        ->and(Album::where('title', 'Product launch')->sole()->hasPassword())->toBeTrue()
        ->and(Album::where('title', 'Client preview: Harbour Hotel')->sole())
        ->visibility->toBe(Visibility::Unlisted)
        ->share_token->not->toBeNull()
        ->access_expires_at->isFuture()->toBeTrue()
        ->and(Album::where('title', 'Team offsite 2026')->sole()->sharedGroups()->pluck('name')->all())->toBe(['Team'])
        ->and(Album::where('title', 'Best of 2026')->sole()->photos()->count())->toBeGreaterThan(0)
        ->and(Photo::onlyTrashed()->count())->toBeGreaterThan(0)
        ->and(Photo::whereNull('taken_at')->count())->toBe(0)
        ->and(Page::where('slug', 'about')->sole()->embeddedPhotoIds())->not->toBeEmpty();

    $types = ActivityEvent::query()->distinct()->pluck('type')->all();
    expect($types)->toContain(
        ActivityType::Created, ActivityType::Updated, ActivityType::Deleted, ActivityType::PhotosUploaded,
        ActivityType::PhotosAdded, ActivityType::PhotosRemoved, ActivityType::PhotosReordered, ActivityType::AlbumsReordered,
        ActivityType::SettingsChanged, ActivityType::ThemeActivated, ActivityType::PluginEnabled,
        ActivityType::Login, ActivityType::AlbumView, ActivityType::LoginFailed, ActivityType::LockedOut,
        ActivityType::PasswordReset, ActivityType::AlbumPasswordFailed,
    );

    $created = ActivityEvent::where('type', ActivityType::Created)->where('subject_type', Album::class)
        ->where('properties->label', 'Landscapes')->sole();
    expect($created->user->email)->toBe('mira@example.com')
        ->and($created->created_at->lt(now()->subDays(300)))->toBeTrue()
        ->and(Carbon::getTestNow())->toBeNull();
});

it('can be run again without duplicating anything', function () {
    $this->seed(DemoSeeder::class);
    $counts = fn () => [User::count(), Group::count(), Album::count(), Photo::withTrashed()->count(), Page::count(), ActivityEvent::count()];
    $before = $counts();

    $this->seed(DemoSeeder::class);

    expect($counts())->toBe($before);
});

it('seeds predefined tags, album and photo tags, and an album that passes its tags on', function () {
    $this->seed(DemoSeeder::class);

    $landscapes = Album::where('title', 'Landscapes')->sole();
    $mountains = Album::where('title', 'Mountains')->sole();

    expect(SiteSettings::predefinedTags())->toContain('Nature')
        ->and(Tagging::namesOf($landscapes))->toBe(['Landscape', 'Nature', 'Sunset'])
        ->and($landscapes->tags_apply_to_sub_albums)->toBeTrue()
        ->and(Tagging::namesFromParents($mountains, $mountains->owner))->toContain('Sunset')
        ->and(Photo::whereHas('tags')->exists())->toBeTrue();
});

it('keeps tags someone changed when it runs again', function () {
    $this->seed(DemoSeeder::class);
    $forest = Album::where('title', 'Forest trails')->sole();
    Tagging::sync($forest, ['Changed'], $forest->owner);

    $this->seed(DemoSeeder::class);

    expect(Tagging::namesOf($forest))->toBe(['Changed']);
});

it('only adds photos to demo albums that are empty', function () {
    $this->seed(DemoSeeder::class);
    $forest = Album::where('title', 'Forest trails')->sole();
    $forest->photos()->detach();

    $this->seed(DemoSeeder::class);

    expect($forest->photos()->count())->toBe(1)
        ->and(Album::where('title', 'Forest trails')->count())->toBe(1);
});
