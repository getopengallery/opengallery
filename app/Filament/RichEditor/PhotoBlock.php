<?php

namespace App\Filament\RichEditor;

use App\Filament\Forms\Components\MediaPickerField;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/** One photo in page content, with optional caption; opens in the lightbox. */
class PhotoBlock extends PageMediaBlock
{
    public static function getId(): string
    {
        return 'photo';
    }

    public static function getLabel(): string
    {
        return __('Photo');
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::OutlinedPhoto;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalWidth(Width::Large)
            ->schema([
                MediaPickerField::make('photo_id')
                    ->label(__('Photo'))
                    ->required()
                    ->chooseLabel(__('Choose photo'))
                    ->confirmLabel(__('Use this photo'))
                    ->pickerHeading(__('Choose a photo'))
                    ->uploadTo('page-images'),
                TextInput::make('caption')->label(__('Caption'))->maxLength(200),
                Select::make('width')
                    ->label(__('Width'))
                    ->options(['text' => __('Same width as the text'), 'wide' => __('Wide')])
                    ->default('text')
                    ->selectablePlaceholder(false),
            ]);
    }

    public static function getPreviewLabel(array $config): string
    {
        return filled($config['caption'] ?? null) ? __('Photo: :caption', ['caption' => $config['caption']]) : __('Photo');
    }

    public static function toPreviewHtml(array $config): string
    {
        return static::previewThumbs([(int) ($config['photo_id'] ?? 0)], 1);
    }

    /** @param array{page?: Page} $data */
    public static function toHtml(array $config, array $data): ?string
    {
        $photo = static::photos([(int) ($config['photo_id'] ?? 0)])->first();
        $page = $data['page'] ?? null;

        if (! $photo || ! ($src = static::url($page, $photo, 'preview'))) {
            return null;
        }

        return static::render('page-photo', [
            'src' => $src,
            'srcset' => static::srcset($page, $photo, ['thumb', 'preview']),
            'alt' => $config['caption'] ?? $photo->title,
            'caption' => $config['caption'] ?? null,
            'wide' => ($config['width'] ?? 'text') === 'wide',
            'ratio' => $photo->width && $photo->height ? round($photo->width / $photo->height, 4) : null,
        ]);
    }
}
