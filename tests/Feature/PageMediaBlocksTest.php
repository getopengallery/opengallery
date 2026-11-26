<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Album;
use App\Models\Page;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

it('shows a photo and a gallery on a page, each opening the lightbox', function () {
    $album = Album::factory()->create();
    [$a, $b, $c] = [galleryPhoto($album, name: 'A.jpg', width: 300, height: 200), galleryPhoto($album, name: 'B.jpg', width: 200, height: 300), galleryPhoto($album, name: 'C.jpg', width: 300, height: 200)];
    $page = Page::factory()->create(['body' => '<p>Our summer.</p>'
        .customBlockMarkup('photo', ['photo_id' => $a->id, 'caption' => 'At the lake', 'width' => 'wide'])
        .customBlockMarkup('gallery', ['photo_ids' => [$c->id, $b->id], 'layout' => 'justified', 'caption' => 'More photos'])]);

    $this->get(route('pages.show', $page))
        ->assertOk()
        ->assertSee('Our summer.')
        ->assertSee('At the lake')
        ->assertSee('og-wide', false)
        ->assertSee(route('pages.photo', [$page, $a, 'preview']), false)
        ->assertSeeInOrder([route('pages.photo', [$page, $c, 'thumb']), route('pages.photo', [$page, $b, 'thumb'])], false)
        ->assertSee('--r: 0.6667', false)
        ->assertSee('ogLightbox', false)
        ->assertSee('More photos');
});

it('serves photos placed on a published page to its visitors, even from private albums', function () {
    $private = Album::factory()->visibility(Visibility::Private)->create();
    $placed = galleryPhoto($private, name: 'Placed.jpg', width: 300, height: 200);
    $other = galleryPhoto($private, name: 'Other.jpg', width: 300, height: 200);
    $page = Page::factory()->create(['body' => customBlockMarkup('photo', ['photo_id' => $placed->id])]);

    $this->get(route('pages.photo', [$page, $placed, 'preview']))->assertOk();
    $this->get(route('pages.photo', [$page, $other, 'preview']))->assertNotFound();
    $this->get(route('media.show', [$placed->original(), 'preview']))->assertNotFound();

    $page->update(['is_published' => false]);
    $this->get(route('pages.photo', [$page, $placed, 'preview']))->assertNotFound();
});

it('keeps photos on members-only pages away from guests', function () {
    $photo = galleryPhoto(Album::factory()->create(), name: 'Lake.jpg', width: 300, height: 200);
    $page = Page::factory()->membersOnly()->create(['body' => customBlockMarkup('gallery', ['photo_ids' => [$photo->id]])]);

    $this->get(route('pages.photo', [$page, $photo, 'thumb']))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('pages.photo', [$page, $photo, 'thumb']))->assertOk();
});

it('offers the photo and gallery blocks in the page editor', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value));

    $this->get(PageResource::getUrl('create'))
        ->assertOk()
        ->assertSee('Gallery')
        ->assertSee('Photo');
});

it('refuses photos on a page that the editor may not see', function (string $type) {
    $this->actingAs(pageEditor());
    $foreign = Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Private)->create())->create();
    $config = $type === 'photo' ? ['photo_id' => $foreign->id] : ['photo_ids' => [$foreign->id]];

    Livewire::test(CreatePage::class)
        ->set('data.title', 'Leak')
        ->set('data.body', customBlockMarkup($type, $config))
        ->call('create')
        ->assertHasErrors(['data.body' => '1 photo in the content is not one you may use. Remove it and save again.']);

    expect(Page::count())->toBe(0);
})->with(['photo', 'gallery']);

it('lets editors place photos they may see', function (Closure $photoFor) {
    $editor = pageEditor();
    $this->actingAs($editor);
    $photo = $photoFor($editor);

    Livewire::test(CreatePage::class)
        ->set('data.title', 'Ours')
        ->set('data.body', customBlockMarkup('gallery', ['photo_ids' => [$photo->id]]))
        ->call('create')
        ->assertHasNoErrors();

    expect(Page::sole()->embeddedPhotoIds())->toBe([$photo->id]);
})->with([
    'from their own private album' => fn (User $editor) => Photo::factory()->inAlbum(Album::factory()->for($editor, 'owner')->create())->create(),
    'from an album shared with them' => function (User $editor) {
        $album = Album::factory()->visibility(Visibility::Restricted)->create();
        $album->sharedUsers()->attach($editor);

        return Photo::factory()->inAlbum($album)->create();
    },
    'from an album for all members' => fn (User $editor) => Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Registered)->create())->create(),
    'uploaded by them' => fn (User $editor) => Photo::factory()->inAlbum(Album::pageImages($editor))->create(['uploader_id' => $editor->id]),
]);

it('keeps photos placed earlier by others when someone else edits the page', function () {
    $placed = Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Private)->create())->create();
    $sneaked = Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Private)->create())->create();
    $page = Page::factory()->create(['body' => customBlockMarkup('photo', ['photo_id' => $placed->id])]);
    $this->actingAs(pageEditor());

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set('data.body', '<p>New intro.</p>'.customBlockMarkup('photo', ['photo_id' => $placed->id]))
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set('data.body', customBlockMarkup('gallery', ['photo_ids' => [$placed->id, $sneaked->id]]))
        ->call('save')
        ->assertHasErrors(['data.body']);

    expect($page->fresh()->embeddedPhotoIds())->toBe([$placed->id]);
});

it('lets only the visitor\'s browser keep photos of a public page', function () {
    $photo = galleryPhoto(Album::factory()->visibility(Visibility::Private)->create(), name: 'Lake.jpg', width: 300, height: 200);
    $page = Page::factory()->create(['body' => customBlockMarkup('photo', ['photo_id' => $photo->id])]);

    $response = $this->get(route('pages.photo', [$page, $photo, 'preview']));

    // Not "public": no shared cache may keep serving it once the page is unpublished.
    $response->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
});
