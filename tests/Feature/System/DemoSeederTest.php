<?php

use App\Enums\ActivityType;
use App\Enums\Visibility;
use App\Extensions\ThemeManager;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Page;
use App\Models\Photo;
use App\Models\User;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Database\Seeders\DemoPagesSeeder;
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

    $landscapes = Album::where('title', 'Landscapes')->sole();
    $mountains = Album::where('title', 'Mountains')->sole();
    expect(SiteSettings::predefinedTags())->toContain('Nature')
        ->and(Tagging::namesOf($landscapes))->toBe(['Landscape', 'Nature', 'Sunset'])
        ->and($landscapes->tags_apply_to_sub_albums)->toBeTrue()
        ->and(Tagging::namesFromParents($mountains, $mountains->owner))->toContain('Sunset')
        ->and(Photo::whereHas('tags')->exists())->toBeTrue();
});

it('can be run again without duplicating anything, keeping changed tags and filling only empty albums', function () {
    $this->seed(DemoSeeder::class);
    $counts = fn () => [User::count(), Group::count(), Album::count(), Photo::withTrashed()->count(), Page::count(), ActivityEvent::count()];
    $before = $counts();

    $this->seed(DemoSeeder::class);

    expect($counts())->toBe($before);

    $forest = Album::where('title', 'Forest trails')->sole();
    Tagging::sync($forest, ['Changed'], $forest->owner);
    $forest->photos()->detach();

    $this->seed(DemoSeeder::class);

    expect(Tagging::namesOf($forest))->toBe(['Changed'])
        ->and($forest->photos()->count())->toBe(1)
        ->and(Album::where('title', 'Forest trails')->count())->toBe(1);
});

it('seeds demo pages once and links them without overriding chosen links', function () {
    $this->seed(DemoPagesSeeder::class);
    $this->seed(DemoPagesSeeder::class);

    $options = app(ThemeManager::class)->options('default');
    expect(Page::count())->toBe(5)
        ->and(Page::whereKey($options['header_pages'])->pluck('slug')->all())->toBe(['about'])
        ->and(Page::whereKey($options['footer_pages'])->pluck('slug')->sort()->values()->all())->toBe(['imprint', 'privacy']);

    $this->get(route('home'))->assertSee('About')->assertSee('Privacy')->assertDontSee('Photo walk guide');

    app(ThemeManager::class)->saveOptions('default', [...$options, 'footer_pages' => []]);
    $this->seed(DemoPagesSeeder::class);

    expect(app(ThemeManager::class)->option('footer_pages'))->toBe([]);
});
