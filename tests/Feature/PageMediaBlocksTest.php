<?php

use App\Actions\AddPhotoToAlbum;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Album;
use App\Models\Page;
use App\Models\Photo;
use App\Models\Role as RoleModel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('gallery'));

function pagePhoto(Album $album, string $name = 'Lake.jpg', int $w = 300, int $h = 200): Photo
{
    $path = tempnam(sys_get_temp_dir(), 'og').'.jpg';
    imagejpeg(imagecreatetruecolor($w, $h), $path);

    return app(AddPhotoToAlbum::class)->handle($album, $path, $name);
}

/** Rich editor markup for a custom block, as Filament stores it. */
function block(string $id, array $config): string
{
    return '<div data-type="customBlock" data-id="'.$id.'" data-config="'.e(json_encode($config)).'"></div>';
}

it('shows a photo and a gallery on a page, each opening the lightbox', function () {
    $album = Album::factory()->create();
    [$a, $b, $c] = [pagePhoto($album, 'A.jpg'), pagePhoto($album, 'B.jpg', 200, 300), pagePhoto($album, 'C.jpg')];
    $page = Page::factory()->create(['body' => '<p>Our summer.</p>'
        .block('photo', ['photo_id' => $a->id, 'caption' => 'At the lake', 'width' => 'wide'])
        .block('gallery', ['photo_ids' => [$c->id, $b->id], 'layout' => 'justified', 'caption' => 'More photos'])]);

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
    $placed = pagePhoto($private, 'Placed.jpg');
    $other = pagePhoto($private, 'Other.jpg');
    $page = Page::factory()->create(['body' => block('photo', ['photo_id' => $placed->id])]);

    $this->get(route('pages.photo', [$page, $placed, 'preview']))->assertOk();
    $this->get(route('pages.photo', [$page, $other, 'preview']))->assertNotFound();
    $this->get(route('media.show', [$placed->original(), 'preview']))->assertNotFound();

    $page->update(['is_published' => false]);
    $this->get(route('pages.photo', [$page, $placed, 'preview']))->assertNotFound();
});

it('keeps photos on members-only pages away from guests', function () {
    $photo = pagePhoto(Album::factory()->create());
    $page = Page::factory()->membersOnly()->create(['body' => block('gallery', ['photo_ids' => [$photo->id]])]);

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

/** Someone who may edit pages but is not staff (cannot see every album). */
function pageEditor(): User
{
    RoleModel::findOrCreate('page editor')->givePermissionTo([Permission::AccessAdmin->value, Permission::ManagePages->value]);

    return tap(User::factory()->create())->assignRole('page editor');
}

it('refuses photos on a page that the editor may not see', function (string $type) {
    $this->actingAs(pageEditor());
    $foreign = Photo::factory()->inAlbum(Album::factory()->visibility(Visibility::Private)->create())->create();
    $config = $type === 'photo' ? ['photo_id' => $foreign->id] : ['photo_ids' => [$foreign->id]];

    Livewire::test(CreatePage::class)
        ->set('data.title', 'Leak')
        ->set('data.body', block($type, $config))
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
        ->set('data.body', block('gallery', ['photo_ids' => [$photo->id]]))
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
    $page = Page::factory()->create(['body' => block('photo', ['photo_id' => $placed->id])]);
    $this->actingAs(pageEditor());

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set('data.body', '<p>New intro.</p>'.block('photo', ['photo_id' => $placed->id]))
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set('data.body', block('gallery', ['photo_ids' => [$placed->id, $sneaked->id]]))
        ->call('save')
        ->assertHasErrors(['data.body']);

    expect($page->fresh()->embeddedPhotoIds())->toBe([$placed->id]);
});

it('lets only the visitor\'s browser keep photos of a public page', function () {
    $photo = pagePhoto(Album::factory()->visibility(Visibility::Private)->create());
    $page = Page::factory()->create(['body' => block('photo', ['photo_id' => $photo->id])]);

    $response = $this->get(route('pages.photo', [$page, $photo, 'preview']));

    // Not "public": no shared cache may keep serving it once the page is unpublished.
    $response->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
});
