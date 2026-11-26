<?php

use App\Enums\Role;
use App\Enums\Visibility;
use App\Filament\Actions\ActionIcons;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\RichEditor\PhotoBlock;
use App\Models\Album;
use App\Models\Page;
use App\Models\Photo;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin));

    it('lists pages, opening a page from its row, and filters them into published pages and drafts', function () {
        $published = Page::factory()->create(['is_published' => true]);
        $draft = Page::factory()->create(['is_published' => false]);

        $page = Livewire::test(ListPages::class);
        $table = $page->instance()->getTable();

        expect($table->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
            ->and($table->hasDeferredFilters())->toBeFalse()
            ->and($table->getRecordUrl($draft))->toBe(PageResource::getUrl('edit', ['record' => $draft]));

        $page->assertActionDoesNotExist(TestAction::make('edit')->table($draft))
            ->filterTable('is_published', false)
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$published]);
    });

    it('offers the photo and gallery blocks in the page form and points to the theme settings menu', function () {
        $this->get(PageResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Gallery')
            ->assertSee('Photo')
            ->assertSee('Appearance &amp; extensions → Themes', false);
    });

    it('lets admins create pages with an address taken from the title', function () {
        Livewire::test(CreatePage::class)
            ->set('data.title', 'About us')
            ->set('data.body', '<p>We are a scout troop.</p>')
            ->set('data.is_published', true)
            ->call('create')
            ->assertHasNoErrors();

        expect(Page::sole()->slug)->toBe('about-us');
    });

    it('shows a delete button with an icon on the page, and links to the site only while it is published', function () {
        $page = Page::factory()->create();
        $draft = Page::factory()->draft()->create();

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->assertActionHasUrl('open', route('pages.show', $page))
            ->assertActionExists('delete')
            ->assertActionHasIcon('delete', ActionIcons::DELETE)
            ->assertActionHasColor('delete', 'danger');
        Livewire::test(EditPage::class, ['record' => $draft->getRouteKey()])
            ->assertActionHidden('open');
    });
});

it('keeps page management to staff who may manage pages', function () {
    $this->actingAs(userWithRole(Role::Moderator))
        ->get(PageResource::getUrl())
        ->assertForbidden();
});

describe('as someone who may edit pages', function () {
    beforeEach(function () {
        Storage::fake('gallery');
        $this->editor = pageEditor();
        $this->actingAs($this->editor);
    });

    it('refuses photos on a page that the editor may not see', function (string $type) {
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
        $photo = $photoFor($this->editor);

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
});

describe('in German', function () {
    it('shows an empty photo block in the page editor in the reader\'s language', function () {
        app()->setLocale('de');

        expect(PhotoBlock::toPreviewHtml([]))->toContain('Noch keine Fotos ausgewählt.');
    });

    it('translates the photo and gallery block forms in the page editor', function (string $block) {
        $missing = [];
        $german = germanTexts();
        app('translator')->handleMissingKeysUsing(function (string $key, array $replace, ?string $locale) use (&$missing, $german) {
            // Developer-facing: Spatie's exception for an unseeded permission, caught by the gate.
            if ($locale === 'de' && $key !== '' && ! isset($german[$key]) && $key !== 'There is no permission named `:permission` for guard `:guard`.') {
                $missing[$key] = true;
            }

            return $key;
        });
        $this->actingAs(userWithRole(Role::SuperAdmin, ['locale' => 'de']));
        app()->setLocale('de');

        // The block forms only open in a modal, which the page renders render without.
        $page = Livewire::test(CreatePage::class)
            ->mountAction(TestAction::make('customBlock')->schemaComponent('body')->arguments(['id' => $block, 'mode' => 'insert', 'config' => []]));

        foreach ($page->instance()->getSchema('mountedActionSchema0')->getFlatComponents() as $component) {
            if ($component instanceof Field) {
                $component->getLabel();
            }
            if ($component instanceof Select || $component instanceof ToggleButtons) {
                $component->getOptions();
            }
        }

        expect(array_keys($missing))->toBe([]);
    })->with(['photo', 'gallery']);
});
