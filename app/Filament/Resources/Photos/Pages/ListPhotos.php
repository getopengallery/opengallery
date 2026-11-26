<?php

namespace App\Filament\Resources\Photos\Pages;

use App\Enums\Permission;
use App\Filament\Actions\PickMediaAction;
use App\Filament\Resources\Photos\Pages\Concerns\HasMediaLayout;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Photos\Tables\PhotosTable;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListPhotos extends ListRecords
{
    use HasMediaLayout;

    protected static string $resource = PhotoResource::class;

    public function getSubheading(): ?string
    {
        return __('All photos you have access to. A photo can be in several albums.');
    }

    /** The resource query includes the trash (for the view page and search); the list does not. */
    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => TrashActions::scope($query, false));
    }

    protected function getHeaderActions(): array
    {
        return [
            TrashActions::open(PhotoResource::class),
            Action::make('uploadPhotos')
                ->label(__('Upload photos'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn (): bool => auth()->user()->can(Permission::UploadPhotos->value))
                ->modalHeading(__('Upload photos'))
                ->modalSubmitActionLabel(__('Continue'))
                ->schema([
                    Select::make('target')
                        ->label(__('Upload to'))
                        ->options(fn (): array => self::uploadTargets())
                        ->default('inbox')
                        ->selectablePlaceholder(false)
                        ->searchable()
                        ->required()
                        ->in(fn (): array => array_keys(self::uploadTargets()))
                        ->helperText(__('Photos in "Unsorted uploads" can be added to albums later.')),
                ])
                ->action(fn (array $data) => $this->replaceMountedAction('uploadInto', ['target' => $data['target']])),
        ];
    }

    /**
     * Second step of "Upload photos": the media picker, uploading into the
     * place chosen in the first step. The upload endpoint checks the target
     * again (PhotoUploads::allows()), so a crafted target uploads nowhere.
     */
    public function uploadIntoAction(): PickMediaAction
    {
        return PickMediaAction::make('uploadInto')
            ->modalHeading(__('Upload photos'))
            ->modalDescription(__('Drop photos on the upload area. They are added to the place you chose; choose "Done" when the upload has finished.'))
            ->pickerKey('media-upload')
            ->multiple()
            ->confirmLabel(__('Done'))
            ->uploadTo(fn (array $arguments): string => (string) ($arguments['target'] ?? 'inbox'))
            ->scopeAlbum(fn (array $arguments): ?int => str_starts_with((string) ($arguments['target'] ?? ''), 'album:')
                ? (int) substr($arguments['target'], 6)
                : null, 'only')
            ->onPicked('$wire.$refresh()');
    }

    /**
     * Where "Upload photos" can put new photos: the person's "Unsorted
     * uploads" first, then every album they may upload into (not system albums).
     *
     * @return array<string, string> upload target => label
     */
    public static function uploadTargets(): array
    {
        $systemAlbums = Album::query()->whereNotNull('system_album')->pluck('id')->all();

        return [
            'inbox' => __('Unsorted uploads'),
            ...collect(PhotosTable::manageableAlbums('upload'))
                ->except($systemAlbums)
                ->mapWithKeys(fn (string $path, int $id) => ["album:{$id}" => $path])
                ->all(),
        ];
    }
}
