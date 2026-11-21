<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Navigation\NavigationGroup;
use App\Filament\Pages\Themes;
use App\Filament\RichEditor\GalleryBlock;
use App\Filament\RichEditor\PhotoBlock;
use App\Models\Page;
use App\Models\User;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make()
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(120),
                        RichEditor::make('body')
                            ->label(__('Content'))
                            ->toolbarButtons([
                                ['h2', 'h3', 'bold', 'italic', 'link'],
                                ['bulletList', 'orderedList', 'blockquote', 'horizontalRule'],
                                ['customBlocks'],
                                ['undo', 'redo'],
                            ])
                            ->customBlocks([PhotoBlock::class, GalleryBlock::class])
                            ->extraInputAttributes(['class' => 'og-page-editor']),
                    ]),
                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make(__('Publishing'))
                            ->schema([
                                Toggle::make('is_published')
                                    ->label(__('Published'))
                                    ->helperText(__('Drafts are only visible here.')),
                                Toggle::make('members_only')
                                    ->label(__('Only for logged-in members'))
                                    ->helperText(__('Leave off for legal pages such as an imprint or privacy policy.')),
                                TextInput::make('slug')
                                    ->label(__('Address'))
                                    ->prefix('/pages/')
                                    ->helperText(__('Leave empty to use the title.'))
                                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Str::slug($state) : null)
                                    ->unique(ignoreRecord: true),
                            ]),
                        Section::make(__('Where it appears'))
                            ->schema([
                                TextEntry::make('links_hint')
                                    ->label(__('Links'))
                                    ->hiddenLabel()
                                    ->state(__('Link pages from the header or footer in :menu, with the gear icon on the active theme.', ['menu' => NavigationGroup::AppearanceAndExtensions->getLabel().' → '.Themes::getNavigationLabel()])),
                            ]),
                    ]),
            ]);
    }

    /**
     * The picker only offers photos the editor may see, but block configs come
     * from the browser: re-check every newly placed photo on the server.
     *
     * @param  array<string, mixed>  $data  the form data about to be saved
     *
     * @throws ValidationException
     */
    public static function ensurePhotosAreUsable(array $data, User $editor, ?Page $page = null): void
    {
        $unusable = Page::unusablePhotoIds($data['body'] ?? null, $editor, $page);

        if ($unusable !== []) {
            throw ValidationException::withMessages([
                'data.body' => trans_choice(':count photo in the content is not one you may use. Remove it and save again.|:count photos in the content are not ones you may use. Remove them and save again.', count($unusable)),
            ]);
        }
    }
}
