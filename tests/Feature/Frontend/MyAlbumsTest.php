<?php

use App\Enums\CollaboratorRole;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Albums\AlbumResource;
use App\Models\Album;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

describe('a person without albums of their own', function () {
    it('is offered a New album button only when they may create albums', function (Role $role, bool $mayCreate) {
        $response = $this->actingAs(userWithRole($role))->get(route('dashboard'))->assertOk();

        if ($mayCreate) {
            $response->assertSee("You don't have any albums of your own yet.")
                ->assertSee(AlbumResource::getUrl('create'))
                ->assertDontSee('Albums that are shared with you will appear here.');
        } else {
            $response->assertSee('Albums that are shared with you will appear here.')
                ->assertDontSee("You don't have any albums of your own yet.")
                ->assertDontSee(AlbumResource::getUrl('create'));
        }
    })->with([
        'uploader' => [Role::Uploader, true],
        'member' => [Role::User, false],
    ]);

    it('lists albums without an owner that are shared with them', function () {
        $member = userWithRole(Role::User);
        $album = Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Orphaned camp', 'owner_id' => null]);
        $album->sharedUsers()->attach($member);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Shared with you')
            ->assertSee('Orphaned camp')
            ->assertDontSee('Albums that are shared with you will appear here.');
    });

    it('lists direct, group and inherited albums under "Shared with you" with the role', function () {
        $user = User::factory()->create();
        $direct = Album::factory()->create(['title' => 'Direct album']);
        addCollaborator($direct, CollaboratorRole::Contributor, $user);
        $throughGroup = Album::factory()->create(['title' => 'Group album']);
        addGroupCollaborator($throughGroup, CollaboratorRole::Organizer, $user);
        $parent = Album::factory()->create(['title' => 'Parent album']);
        addCollaborator($parent, CollaboratorRole::Editor, $user);
        Album::factory()->for($parent->owner, 'owner')->create(['title' => 'Inherited album', 'parent_id' => $parent->id]);
        Album::factory()->create(['title' => 'Unrelated album']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(['Shared with you', 'Manage albums', 'Direct album', 'Group album', 'Inherited album', 'Your role: Contributor', 'Your role: Organizer', 'Your role: Editor'])
            ->assertDontSee('Unrelated album');
    });
});

describe('a person with albums of their own', function () {
    it('splits a long list into pages', function () {
        $uploader = userWithRole(Role::Uploader);
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

describe('the Favorites tile', function () {
    beforeEach(fn () => Storage::fake('gallery'));

    it('appears once the person has a favourite they can see, links to the favorites page and counts the photos', function () {
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee(route('favorites.index'));

        $user->favoritePhotos()->attach($photo);

        $this->get(route('dashboard'))->assertOk()->assertSee(route('favorites.index'))->assertSee('1 photo');

        $user->favoritePhotos()->attach(favoritesPublicPhoto());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('favorites.index').'"', false)
            ->assertSee('Favorites')
            ->assertSee('2 photos');
    });

    it('is not shown when the only favourite is in the trash', function () {
        $user = User::factory()->create();
        $photo = favoritesPublicPhoto();
        $user->favoritePhotos()->attach($photo);
        $photo->delete();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee(route('favorites.index'));
    });
});
