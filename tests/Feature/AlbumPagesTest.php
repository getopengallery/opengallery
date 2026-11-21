<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use App\Support\PhotoGridItems;
use Illuminate\Support\Facades\Storage;

/** A real photo (with its image files) in $album. */
function sitePhoto(Album $album, array $attributes = []): Photo
{
    Storage::fake('gallery');
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    imagejpeg(imagecreatetruecolor(160, 120), $path);

    return tap(app(AddPhotoToAlbum::class)->handle($album, $path, 'photo.jpg'))->update($attributes);
}

function withRole(Role $role): User
{
    return tap(User::factory()->create())->assignRole($role->value);
}

describe('my albums', function () {
    it('tells people who cannot create albums that shared albums will appear there', function () {
        $this->actingAs(withRole(Role::User))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Albums that are shared with you will appear here.')
            ->assertDontSee("You don't have any albums of your own yet.")
            ->assertDontSee(AlbumResource::getUrl('create'));
    });

    it('offers people who create albums a New album button when they have none', function () {
        $this->actingAs(withRole(Role::Uploader))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee("You don't have any albums of your own yet.")
            ->assertSee(AlbumResource::getUrl('create'));
    });

    it('lists albums without an owner that are shared with the person', function () {
        $member = withRole(Role::User);
        $album = Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Orphaned camp', 'owner_id' => null]);
        $album->sharedUsers()->attach($member);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Shared with you')
            ->assertSee('Orphaned camp')
            ->assertDontSee('Albums that are shared with you will appear here.');
    });

    it('splits a long list of own albums into pages', function () {
        $uploader = withRole(Role::Uploader);
        foreach (range(1, 25) as $i) {
            Album::factory()->for($uploader, 'owner')->create([
                'title' => sprintf('Album %02d', $i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($uploader)->get(route('dashboard'))
            ->assertSee('Album 24')->assertDontSee('Album 25');
        $this->actingAs($uploader)->get(route('dashboard', ['own' => 2]))
            ->assertSee('Album 25')->assertDontSee('Album 24');
    });
});

describe('album list', function () {
    it('offers people who create albums a New album button when they see no albums', function () {
        $this->actingAs(withRole(Role::Uploader))
            ->get(route('home'))
            ->assertOk()
            ->assertSee(AlbumResource::getUrl('create'));

        $this->actingAs(withRole(Role::User))
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee(AlbumResource::getUrl('create'));
    });
});

describe('album page', function () {
    it('gives the owner buttons to add photos and edit the album', function () {
        $uploader = withRole(Role::Uploader);
        $album = Album::factory()->for($uploader, 'owner')->create();

        $this->actingAs($uploader)
            ->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee(PhotosRelationManager::addPhotosUrl($album))
            ->assertSee(AlbumResource::getUrl('edit', ['record' => $album]));
    });

    it('gives staff who may edit every album the same buttons on other people\'s albums', function () {
        $album = Album::factory()->visibility(Visibility::Registered)->create();

        $this->actingAs(withRole(Role::Moderator))
            ->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee(PhotosRelationManager::addPhotosUrl($album))
            ->assertSee(AlbumResource::getUrl('edit', ['record' => $album]));
    });

    it('shows no admin buttons to people who only see the album', function () {
        $member = withRole(Role::User);
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedUsers()->attach($member);

        $this->actingAs($member)
            ->get(route('albums.show', $album))
            ->assertOk()
            ->assertDontSee(AlbumResource::getUrl('edit', ['record' => $album]))
            ->assertDontSee('Add photos');
    });

    it('lets the owner copy the share link of an unlisted album, but not its visitors', function () {
        $uploader = withRole(Role::Uploader);
        $album = Album::factory()->for($uploader, 'owner')->visibility(Visibility::Unlisted)->create();
        $shareUrl = route('albums.shared', $album->share_token);

        $this->actingAs($uploader)
            ->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee('Copy share link')
            ->assertSee($album->share_token);

        auth()->logout();
        $this->get($shareUrl);
        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertDontSee('Copy share link');
    });

    it('splits many albums inside an album into pages', function () {
        $camp = Album::factory()->visibility(Visibility::Public)->create();
        foreach (range(1, 25) as $i) {
            Album::factory()->visibility(Visibility::Public)->create([
                'title' => sprintf('Day %02d', $i),
                'parent_id' => $camp->id,
                'sort_order' => $i,
            ]);
        }

        $this->get(route('albums.show', $camp))
            ->assertSee('Day 24')->assertDontSee('Day 25');
        $this->get(route('albums.show', [$camp, 'albums' => 2]))
            ->assertSee('Day 25')->assertDontSee('Day 24');
    });
});

describe('photo viewer', function () {
    it('shows each photo\'s description and date taken in the viewer', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        sitePhoto($album, ['description' => 'Sunset over the lake', 'taken_at' => '2026-07-14 20:30:00']);

        $this->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee('Sunset over the lake')
            ->assertSee('14.07.2026');
    });

    it('numbers photos across pages and links the first and last photo of a page to the neighbouring pages', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        $photos = collect(range(1, 3))->map(fn () => sitePhoto($album));

        $first = PhotoGridItems::from($album->photos()->paginate(2, page: 1))->pluck('lightbox');
        $second = PhotoGridItems::from($album->photos()->paginate(2, page: 2))->pluck('lightbox');

        expect($first->pluck('position')->all())->toBe([1, 2])
            ->and($first->pluck('total')->all())->toBe([3, 3])
            ->and($first[0])->not->toHaveKey('previousPage')
            ->and($first[1]['nextPage'])->toEndWith('?page=2#photo-first')
            ->and($second[0]['position'])->toBe(3)
            ->and($second[0]['id'])->toBe($photos[2]->id)
            ->and($second[0]['previousPage'])->toEndWith('?page=1#photo-last')
            ->and($second[0])->not->toHaveKey('nextPage');
    });

    it('lets the viewer load the neighbouring page\'s photos instead of opening that page', function () {
        $album = Album::factory()->visibility(Visibility::Public)->create();
        collect(range(1, 3))->each(fn () => sitePhoto($album));

        $first = PhotoGridItems::from($album->photos()->paginate(2, page: 1))->pluck('lightbox');
        $second = PhotoGridItems::from($album->photos()->paginate(2, page: 2))->pluck('lightbox');

        expect($first[1]['nextItems'])->toBe(route('albums.photos', [$album, 'page' => 2]))
            ->and($second[0]['previousItems'])->toBe(route('albums.photos', [$album, 'page' => 1]))
            ->and($first[0])->not->toHaveKey('previousItems')
            ->and($second[0]['page'])->not->toBe($first[0]['page']);

        $photo = $album->photos()->first();

        $this->getJson(route('albums.photos', [$album, 'page' => 1]))
            ->assertOk()
            ->assertJsonPath('items.0.id', $photo->id)
            ->assertJsonPath('items.0.position', 1)
            ->assertJsonPath('items.0.page', route('albums.show', [$album, 'page' => 1]));
    });

    it('only gives the viewer the photos of albums the visitor may open', function () {
        $private = Album::factory()->create();
        $locked = Album::factory()->visibility(Visibility::Public)->create(['password' => 'lake-2026']);

        $this->getJson(route('albums.photos', $private))->assertNotFound();
        $this->getJson(route('albums.photos', $locked))->assertNotFound();

        $this->post(route('albums.unlock', $locked), ['password' => 'lake-2026']);
        $this->getJson(route('albums.photos', $locked))->assertOk();
    });
});

describe('no access', function () {
    it('tells members who may not see an album what to do, without showing the album', function () {
        $album = Album::factory()->create(['title' => 'Secret plans']);

        $this->actingAs(withRole(Role::User))
            ->get(route('albums.show', $album))
            ->assertForbidden()
            ->assertSee("You can't open this album")
            ->assertSee(route('home'))
            ->assertDontSee('Secret plans');
    });

    it('still answers guests as if the album did not exist', function () {
        $album = Album::factory()->create();

        $this->get(route('albums.show', $album))->assertNotFound();
    });

    it('shows other refusals in the site\'s look with a way back', function () {
        $this->actingAs(withRole(Role::Moderator))
            ->get(UserResource::getUrl())
            ->assertForbidden()
            ->assertSee("You don't have access to this page")
            ->assertSee(route('home'));
    });
});

describe('album search', function () {
    it('finds albums by title or description, also inside other albums', function () {
        $camp = Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp']);
        Album::factory()->visibility(Visibility::Public)->create(['title' => 'Day 3', 'description' => 'Canoeing on the lake', 'parent_id' => $camp->id]);
        Album::factory()->visibility(Visibility::Public)->create(['title' => 'Winter hike']);

        $this->get(route('home', ['q' => 'lake']))
            ->assertOk()
            ->assertSee('1 album matches "lake".')
            ->assertSee('Day 3')
            ->assertDontSee('Winter hike');
        $this->get(route('home', ['q' => 'CAMP']))
            ->assertSee('Summer camp')
            ->assertDontSee('Winter hike');
    });

    it('leaves out albums the visitor may not see, and visible albums inside hidden ones', function () {
        $hidden = Album::factory()->create(['title' => 'Private lake trip']);
        Album::factory()->visibility(Visibility::Public)->create(['title' => 'Lake day', 'parent_id' => $hidden->id]);
        Album::factory()->visibility(Visibility::Unlisted)->create(['title' => 'Lake party']);

        $this->get(route('home', ['q' => 'lake']))
            ->assertOk()
            ->assertSee('No albums match "lake".')
            ->assertDontSee('Private lake trip')
            ->assertDontSee('Lake day')
            ->assertDontSee('Lake party');
    });

    it('treats % and _ in a search as plain characters', function () {
        Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp']);

        $this->get(route('home', ['q' => '%']))
            ->assertOk()
            ->assertSee('No albums match "%".')
            ->assertDontSee('Summer camp');
    });

    it('ignores a search that is not plain text', function () {
        Album::factory()->visibility(Visibility::Public)->create(['title' => 'Summer camp']);

        $this->get('/?q[]=camp')->assertOk()->assertSee('Summer camp');
    });

    it('keeps the search when moving to the next page of results', function () {
        foreach (range(1, 25) as $i) {
            Album::factory()->visibility(Visibility::Public)->create(['title' => sprintf('Camp %02d', $i)]);
        }

        $this->get(route('home', ['q' => 'camp']))
            ->assertSee('25 albums match "camp".')
            ->assertSee('q=camp&amp;page=2', false);
    });
});
