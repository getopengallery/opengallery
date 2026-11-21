<?php

namespace App\Filament\Clusters\Settings;

use App\Enums\ImagePreview;
use App\Enums\Permission;
use App\Filament\Forms\Components\ImageUpload;
use App\Models\Setting;
use App\Support\ActivityLog;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One topic of Admin → Settings: its own form, saved on its own. A page lists
 * its fields in FIELDS and provides formValues() and formSections(); values
 * are stored in the `settings` table (see App\Support\SiteSettings).
 */
abstract class SettingsPage extends Page
{
    use HasUnsavedDataChangesAlert;

    /**
     * Form field => [setting key, name in the activity log and the admin search].
     * The names are stored in English and translated when shown.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected const FIELDS = [];

    /** @var list<string> */
    protected const FILE_FIELDS = [];

    /** @var list<string> */
    protected const INTEGER_FIELDS = [];

    /** @var list<string> Stored encrypted; a value sent for them always counts as a change. */
    protected const SECRET_FIELDS = [];

    protected static ?string $cluster = SettingsCluster::class;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed> Values shown when the page opened, to report what changed. */
    public array $original = [];

    /** @return array<string, mixed> the form's values as stored */
    abstract protected function formValues(): array;

    /** @return array<int, Component> */
    abstract protected function formSections(): array;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageSettings->value) ?? false;
    }

    public function getTitle(): string
    {
        return (string) static::getNavigationLabel();
    }

    /** The words the admin search looks through: the page's name and its fields. */
    public static function searchText(): string
    {
        $fields = array_map(fn (array $field): string => __($field[1]), static::FIELDS);

        return implode(' ', [static::getNavigationLabel(), ...array_values($fields)]);
    }

    public function mount(): void
    {
        $this->form->fill($this->original = $this->formValues());
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components($this->formSections());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Save settings'))->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $changed = [];

        foreach (static::FIELDS as $field => [$key, $label]) {
            // Hidden fields are not part of the state; keep their stored values.
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if (in_array($field, static::FILE_FIELDS, true)) {
                $previous = Setting::get($key);

                if ($previous && $previous !== $value) {
                    Storage::disk(SiteSettings::DISK)->delete($previous);
                }

                if ($previous !== $value) {
                    $this->fileReplaced($field);
                }
            }

            $value = $value instanceof BackedEnum ? $value->value : $value;
            $value = in_array($field, static::INTEGER_FIELDS, true) ? (int) $value : $value;
            $value = is_string($value) ? (trim($value) ?: null) : $value;
            $value = $this->prepareValue($field, $value);

            if (in_array($field, static::SECRET_FIELDS, true)) {
                $changed[] = $label;
                Setting::put($key, SiteSettings::encrypt($value));

                continue;
            }

            if (($this->original[$field] ?? null) !== $value) {
                $changed[] = $label;
            }

            Setting::put($key, $value);
        }

        $this->afterFieldsStored($data);

        // Log with whichever level records it, so turning the log off (or on) is traced too.
        if (SiteSettings::activityLevel()->logsChanges()) {
            ActivityLog::settingsChanged($changed);
            SiteSettings::apply();
        } else {
            SiteSettings::apply();
            ActivityLog::settingsChanged($changed);
        }

        $this->afterSettingsApplied();
        $this->rememberData();

        Notification::make()->title(__('Settings saved'))->success()->send();
    }

    /** Warns before leaving the page with changes that are not saved. */
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

    protected function prepareValue(string $field, mixed $value): mixed
    {
        return $value;
    }

    protected function fileReplaced(string $field): void {}

    /** @param  array<string, mixed>  $data */
    protected function afterFieldsStored(array $data): void {}

    protected function afterSettingsApplied(): void {}

    protected static function brandingImage(string $field, string $label): ImageUpload
    {
        return ImageUpload::make($field)
            ->previewAs(ImagePreview::Logo)
            ->label($label)
            ->disk(SiteSettings::DISK)
            ->directory(SiteSettings::DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/webp', 'image/jpeg'])
            ->maxSize(1024);
    }
}
