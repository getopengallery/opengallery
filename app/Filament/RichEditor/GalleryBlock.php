<?php

namespace App\Filament\RichEditor;

use App\Filament\Forms\Components\MediaPickerField;
use App\Models\Page;
use App\Models\Photo;
use App\Support\ImageLoading;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/** Several photos side by side in page content; each opens the lightbox. */
class GalleryBlock extends PageMediaBlock
{
    public static function getId(): string
    {
        return 'gallery';
    }

    public static function getLabel(): string
    {
        return __('Gallery');
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::OutlinedSquares2x2;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalWidth(Width::TwoExtraLarge)
            ->schema([
                MediaPickerField::make('photo_ids')
                    ->label(__('Photos'))
                    ->helperText(__('They appear in the order you add them.'))
                    ->multiple()
                    ->required()
                    ->chooseLabel(__('Add photos'))
                    ->confirmLabel(__('Add to gallery'))
                    ->pickerHeading(__('Choose photos for the gallery'))
                    ->uploadTo('page-images'),
                Grid::make(2)->schema([
                    ToggleButtons::make('layout')
                        ->label(__('Layout'))
                        ->options(['grid' => __('Grid'), 'justified' => __('Rows')])
                        ->helperText(__('Grid: equal squares. Rows: photos keep their shape, like in albums.'))
                        ->default('grid')
                        ->inline()
                        ->live(),
                    Select::make('columns')
                        ->label(__('Photos per row'))
                        ->options(collect([2, 3, 4])->mapWithKeys(fn (int $count) => [$count => __(':count per row', ['count' => $count])])->all())
                        ->default(3)
                        ->selectablePlaceholder(false)
                        ->visible(fn (Get $get) => $get('layout') !== 'justified'),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('caption')->label(__('Caption'))->maxLength(200),
                    Select::make('width')
                        ->label(__('Width'))
                        ->options(['text' => __('Same width as the text'), 'wide' => __('Wide')])
                        ->default('wide')
                        ->selectablePlaceholder(false),
                ]),
            ]);
    }

    public static function getPreviewLabel(array $config): string
    {
        $count = count((array) ($config['photo_ids'] ?? []));

        return trans_choice('Gallery with :count photo|Gallery with :count photos', $count);
    }

    public static function toPreviewHtml(array $config): string
    {
        return static::previewThumbs((array) ($config['photo_ids'] ?? []));
    }

    /** @param array{page?: Page} $data */
    public static function toHtml(array $config, array $data): ?string
    {
        $page = $data['page'] ?? null;
        $items = static::photos((array) ($config['photo_ids'] ?? []))
            ->map(fn (Photo $photo) => [
                'thumb' => static::url($page, $photo, 'thumb'),
                'srcset' => static::srcset($page, $photo, ['small', 'thumb']),
                'ratio' => $photo->width && $photo->height ? round($photo->width / $photo->height, 4) : 1.5,
                ...ImageLoading::next(),
                'lightbox' => ['src' => static::url($page, $photo, 'preview'), 'title' => $photo->title],
            ])
            ->filter(fn (array $item) => $item['thumb'] && $item['lightbox']['src'])
            ->values();

        if ($items->isEmpty()) {
            return null;
        }

        return static::render('page-gallery', [
            'items' => $items,
            'layout' => ($config['layout'] ?? 'grid') === 'justified' ? 'justified' : 'grid',
            'columns' => in_array($columns = (int) ($config['columns'] ?? 3), [2, 3, 4], true) ? $columns : 3,
            'caption' => $config['caption'] ?? null,
            'wide' => ($config['width'] ?? 'wide') === 'wide',
        ]);
    }
}
