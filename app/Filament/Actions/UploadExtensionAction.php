<?php

namespace App\Filament\Actions;

use App\Enums\ActivityType;
use App\Extensions\ExtensionInstaller;
use App\Extensions\ExtensionInstallFailed;
use App\Extensions\Manifest;
use App\Support\ActivityLog;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "Upload theme" / "Upload plugin" in the header of the Themes and Plugins
 * pages: a zip file is installed through App\Extensions\ExtensionInstaller,
 * logged, and the list reloads. Shown only while ExtensionInstaller::allowed().
 *
 *   UploadExtensionAction::make()->themes()
 *   UploadExtensionAction::make()->plugins()
 */
class UploadExtensionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'upload';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => ExtensionInstaller::allowed())
            ->modalIcon(Heroicon::OutlinedArrowUpTray)
            ->modalSubmitActionLabel(__('Install'))
            ->schema([
                FileUpload::make('package')
                    ->label(__('Zip file'))
                    ->required()
                    ->storeFiles(false)
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip'])
                    ->maxSize(ExtensionInstaller::MAX_UPLOAD_KB),
                Toggle::make('replace')
                    ->label(__('Replace the installed version'))
                    ->helperText(__('Needed to update one that is already installed under the same folder name. Its settings are kept.')),
            ]);
    }

    public function themes(): static
    {
        return $this->label(__('Upload theme'))
            ->modalHeading(__('Upload a theme'))
            ->modalDescription(__('A zip file with the theme folder and its :file. Themes can contain code that runs on the server: only upload themes from sources you trust.', ['file' => 'theme.json']))
            ->action(fn (array $data, Page $livewire) => $this->install(
                $data,
                $livewire,
                fn (string $path, string $name, bool $replace): Manifest => app(ExtensionInstaller::class)->installTheme($path, $name, $replace),
                ActivityType::ThemeInstalled,
                __('Theme installed'),
                fn (string $name): string => __(':name is now in the list. Activate it to use it.', ['name' => $name]),
                __('The theme was not installed'),
            ));
    }

    public function plugins(): static
    {
        return $this->label(__('Upload plugin'))
            ->modalHeading(__('Upload a plugin'))
            ->modalDescription(__('A zip file with the plugin folder and its :file. Plugins run with full access to the site: only upload plugins from sources you trust.', ['file' => 'plugin.json']))
            ->action(fn (array $data, Page $livewire) => $this->install(
                $data,
                $livewire,
                fn (string $path, string $name, bool $replace): Manifest => app(ExtensionInstaller::class)->installPlugin($path, $name, $replace),
                ActivityType::PluginInstalled,
                __('Plugin installed'),
                fn (string $name): string => __(':name is now in the list. It stays off until you turn it on.', ['name' => $name]),
                __('The plugin was not installed'),
            ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(string, string, bool): Manifest  $installer  zip path, original file name, replace
     * @param  Closure(string): string  $describe  the success message for the extension's name
     */
    private function install(array $data, Page $livewire, Closure $installer, ActivityType $type, string $installed, Closure $describe, string $failed): void
    {
        abort_unless($livewire::canAccess() && ExtensionInstaller::allowed(), 403);

        $file = $data['package'];
        abort_unless($file instanceof TemporaryUploadedFile, 422);

        try {
            $extension = $installer($file->getRealPath(), $file->getClientOriginalName(), (bool) ($data['replace'] ?? false));
        } catch (ExtensionInstallFailed $exception) {
            Notification::make()->title($failed)->body($exception->getMessage())->danger()->persistent()->send();
            $this->halt();
        }

        $file->delete();
        ActivityLog::extensionChanged($type, $extension->name);

        Notification::make()
            ->title($installed)
            ->body($describe($extension->label()))
            ->success()
            ->send();

        // The list (and, for a replaced plugin, its pages) are read per request.
        $livewire->redirect($livewire::getUrl());
    }
}
