<?php

namespace App\Filament\Forms\Components;

use App\Enums\ImagePreview;
use Closure;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Number;

/**
 * The admin's field for one standalone image (logo, icon, background, profile
 * picture). Validation, storage and saving are Filament's FileUpload; only
 * the part people see is replaced (filament.forms.image-upload, Alpine
 * component "ogImageUpload").
 *
 *   ImageUpload::make('logo')->previewAs(ImagePreview::Logo)
 *   ImageUpload::make('favicon')->previewAs(ImagePreview::Icon)->previewChip(':name', 'name', 'My site')
 */
class ImageUpload extends FileUpload
{
    /** Browser event after a save stored this field's image. */
    public const SAVED_EVENT = 'og-image-upload-saved';

    /** @var array<string, string> */
    private const FORMAT_NAMES = [
        'image/png' => 'PNG',
        'image/svg+xml' => 'SVG',
        'image/webp' => 'WebP',
        'image/jpeg' => 'JPG',
        'image/gif' => 'GIF',
        'image/x-icon' => 'ICO',
        'image/vnd.microsoft.icon' => 'ICO',
    ];

    protected ImagePreview|Closure $previewStyle = ImagePreview::Wide;

    protected string|Closure|null $chipText = null;

    protected ?string $chipNameField = null;

    protected string|Closure|null $chipFallbackName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->image();
    }

    public function previewAs(ImagePreview|Closure $style): static
    {
        $this->previewStyle = $style;

        return $this;
    }

    /**
     * A small sample next to an Icon preview, at the size the icon is used.
     *
     * @param  string|Closure  $text  Sample text; ":name" is filled in from $nameField.
     * @param  string|null  $nameField  Field of the same form that holds the name.
     * @param  string|Closure|null  $fallbackName  Used while that field is empty.
     */
    public function previewChip(string|Closure $text, ?string $nameField = null, string|Closure|null $fallbackName = null): static
    {
        $this->chipText = $text;
        $this->chipNameField = $nameField;
        $this->chipFallbackName = $fallbackName;

        return $this;
    }

    public function getPreviewStyle(): ImagePreview
    {
        return $this->evaluate($this->previewStyle);
    }

    /** @return array{text: string, path: string|null, fallback: string}|null */
    public function getPreviewChip(): ?array
    {
        $text = $this->evaluate($this->chipText);

        if ($text === null) {
            return null;
        }

        return [
            'text' => $text,
            'path' => $this->chipNameField ? $this->resolveRelativeStatePath($this->chipNameField) : null,
            'fallback' => (string) $this->evaluate($this->chipFallbackName),
        ];
    }

    /** The accepted formats in words, such as "PNG, SVG or WebP". */
    public function getFormatsLabel(): string
    {
        $names = array_values(array_unique(array_filter(array_map(
            fn (string $type): ?string => self::FORMAT_NAMES[$type] ?? null,
            $this->getAcceptedFileTypes() ?? [],
        ))));

        if (count($names) < 2) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return __(':items or :last', ['items' => implode(', ', $names), 'last' => $last]);
    }

    public function getMaxSizeLabel(): ?string
    {
        $maxSize = $this->getMaxSize();

        return $maxSize ? Number::fileSize($maxSize * 1024, maxPrecision: 1) : null;
    }

    /**
     * Everything the browser side needs.
     *
     * @return array<string, mixed>
     */
    public function getUploadConfig(): array
    {
        $title = (string) $this->getLabel();

        return [
            'statePath' => $this->getStatePath(),
            'files' => $this->getCurrentFiles(),
            'accepted' => $this->getAcceptedFileTypes() ?? [],
            'maxKb' => $this->getMaxSize(),
            'locale' => app()->getLocale(),
            'isDisabled' => $this->isDisabled(),
            'canRemove' => $this->isDeletable(),
            'title' => $title,
            'formatNames' => self::FORMAT_NAMES,
            'chip' => $this->getPreviewChip(),
            'uploadingMessage' => $this->getUploadingMessage(),
            'messages' => [
                'uploading' => __('Uploading, :percent%'),
                'imageOfType' => __(':type image'),
                'image' => __('Image'),
                'previewOf' => __('Preview of :title', ['title' => $title]),
                'tooLarge' => __('This file is too large. The limit is :size.', ['size' => $this->getMaxSizeLabel() ?? '']),
                'wrongType' => __('This file type is not allowed. Use :formats.', ['formats' => $this->getFormatsLabel()]),
                'failed' => __('The upload failed. Please try again.'),
            ],
        ];
    }

    /**
     * Stored files of this field, so the preview shows without another request.
     * State that is not a list yet (a file set directly) has none.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function getCurrentFiles(): array
    {
        return is_array($this->getRawState()) ? ($this->getUploadedFiles() ?? []) : [];
    }

    /** Runs only after the form passed validation, so the field can stop showing "Not saved yet". */
    public function saveUploadedFiles(): void
    {
        parent::saveUploadedFiles();

        $this->getLivewire()->dispatch(self::SAVED_EVENT, statePath: $this->getStatePath());
    }

    public function toEmbeddedHtml(): string
    {
        return $this->wrapEmbeddedHtml(
            view('filament.forms.image-upload', ['field' => $this])->render(),
            labelTag: 'div',
        );
    }
}
