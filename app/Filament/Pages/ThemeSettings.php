<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Extensions\Manifest;
use App\Extensions\ThemeManager;
use App\Filament\Forms\Components\ImageUpload;
use App\Models\Page as ContentPage;
use App\Support\ActivityLog;
use Filament\Actions\Action;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Admin → Themes → ⚙: the settings a theme declares in its theme.json
 * ("settings": sections of fields), rendered as a form.
 *
 * Field types: text, textarea, toggle, select (options), layout (options,
 * shown as buttons), image, pages, palette, colors (tokens), css.
 * Optional keys: label, help, default, visible_if {key: value},
 * block (the field picks that block's variant), css_variable.
 *
 * Texts from theme.json (labels, help, descriptions, option and palette
 * names) are shown through Manifest::text(): a key like
 * "theme-default::settings.palette" comes from the theme's own language
 * files, plain text is shown as written.
 */
class ThemeSettings extends Page
{
    protected static ?string $slug = 'themes/settings';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $theme = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageThemes->value) ?? false;
    }

    public function mount(): void
    {
        $themes = app(ThemeManager::class);
        $this->theme = $themes->all()->has($this->theme) ? $this->theme : (string) $themes->active()?->slug;

        abort_if($themes->settingSections($this->theme) === [], 404);

        $this->form->fill($themes->options($this->theme));
    }

    public function getTitle(): string|Htmlable
    {
        $theme = app(ThemeManager::class)->all()->get($this->theme);

        return __(':name theme', ['name' => $theme?->label() ?? $this->theme]);
    }

    public function getSubheading(): ?string
    {
        return app(ThemeManager::class)->active()?->slug === $this->theme
            ? __('Changes show on the site as soon as you save.')
            : __('This theme is not active. Activate it on the Themes page to see these settings on the site.');
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [Themes::getUrl() => __('Themes'), __('Settings')];
    }

    public function form(Schema $schema): Schema
    {
        $sections = app(ThemeManager::class)->settingSections($this->theme);

        return $schema
            ->statePath('data')
            ->components(array_map(fn (array $section) => Section::make(Manifest::text($section['label'] ?? ''))
                ->description(Manifest::text($section['description'] ?? null))
                ->collapsible()
                ->schema(array_map(fn (array $field) => $this->field($field), array_values(array_filter($section['fields'], fn ($f) => isset($f['key'], $f['type']))))),
                $sections));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Save'))->submit('save')->keyBindings(['mod+s']),
                        Action::make('view')->label(__('View site'))->color('gray')->url(route('home'))->openUrlInNewTab(),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $themes = app(ThemeManager::class);
        $previous = $themes->options($this->theme);
        $values = $this->form->getState();

        // Remove images that were replaced or cleared.
        foreach ($themes->settingFields($this->theme) as $field) {
            $old = $previous[$field['key']] ?? null;
            if ($field['type'] === 'image' && is_string($old) && $old !== ($values[$field['key']] ?? null)) {
                Storage::disk(ThemeManager::UPLOAD_DISK)->delete($old);
            }
        }

        $themes->saveOptions($this->theme, [...$previous, ...$values]);

        // Which options changed (by their labels as written in theme.json, translated when shown), not their values.
        $changed = collect($themes->settingSections($this->theme))
            ->flatMap(fn (array $section) => collect($section['fields'] ?? [])
                ->filter(fn (array $field) => isset($field['key']) && array_key_exists($field['key'], $values) && ($previous[$field['key']] ?? null) != $values[$field['key']])
                ->map(fn (array $field) => array_values(array_filter([$section['label'] ?? null, $field['label'] ?? Str::headline($field['key'])]))))
            ->unique(fn (array $parts) => implode("\0", $parts))
            ->values();

        if ($changed->isNotEmpty()) {
            ActivityLog::settingsChanged([['theme' => $themes->all()->get($this->theme)?->name ?? $this->theme, 'options' => $changed->all()]]);
        }

        Notification::make()->title(__('Theme settings saved'))->success()->send();
    }

    /** @param array<string, mixed> $field */
    private function field(array $field): Component
    {
        $key = $field['key'];
        $label = Manifest::text($field['label'] ?? null) ?? str($key)->headline()->lower()->ucfirst()->value();
        $options = array_map(fn (mixed $option) => Manifest::text($option), (array) ($field['options'] ?? []));

        $component = match ($field['type']) {
            'textarea' => Textarea::make($key)->rows(4),
            'toggle' => Toggle::make($key),
            'select' => Select::make($key)->options($options)->selectablePlaceholder(false),
            'layout' => ToggleButtons::make($key)->options($options)->inline(),
            'image' => ImageUpload::make($key)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize((int) ($field['max_kb'] ?? 4096))
                ->disk(ThemeManager::UPLOAD_DISK)
                ->directory('themes/'.$this->theme)
                ->visibility('private'),
            'pages' => Select::make($key)
                ->multiple()
                ->options(fn () => ContentPage::published()->orderBy('title')->pluck('title', 'id'))
                ->helperText(Manifest::text($field['help'] ?? null) ?? __('Published pages, in the order you pick them. Create pages under Gallery → Pages.')),
            'palette' => ToggleButtons::make($key)
                ->options(collect(app(ThemeManager::class)->palettes($this->theme))->map(fn ($p, $k) => Manifest::text($p['label'] ?? $k))->all())
                ->inline()
                ->live(),
            'colors' => Grid::make(2)->schema([
                Section::make(__('Light mode'))->compact()->schema($this->colorPickers($key, 'light', (array) ($field['tokens'] ?? []))),
                Section::make(__('Dark mode'))->compact()->schema($this->colorPickers($key, 'dark', (array) ($field['tokens'] ?? []))),
            ]),
            'css' => CodeEditor::make($key)->language(Language::Css),
            default => TextInput::make($key),
        };

        if ($component instanceof Grid) {
            $component->columnSpanFull();
        } else {
            // Manifest texts are translated already (Manifest::text()).
            $component->label($label)->translateLabel(false)->default($field['default'] ?? null);

            if (isset($field['help']) && $field['type'] !== 'pages') {
                $component->helperText(Manifest::text($field['help']));
            }
        }

        if ($conditions = (array) ($field['visible_if'] ?? [])) {
            $component->visible(function (Get $get) use ($conditions) {
                foreach ($conditions as $other => $expected) {
                    if ($get($other) != $expected) {
                        return false;
                    }
                }

                return true;
            });
        }

        // Let fields that others depend on refresh the form.
        $dependedOn = collect(app(ThemeManager::class)->settingFields($this->theme))->flatMap(fn ($f) => array_keys((array) ($f['visible_if'] ?? [])));
        if ($dependedOn->contains($key) && method_exists($component, 'live')) {
            $component->live();
        }

        return $component;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<ColorPicker>
     */
    private function colorPickers(string $key, string $mode, array $tokens): array
    {
        $names = ['canvas' => __('Background'), 'surface' => __('Surface'), 'ink' => __('Text'), 'muted' => __('Secondary text'), 'line' => __('Lines'), 'primary' => __('Primary'), 'on-primary' => __('Text on primary')];

        return array_map(fn (string $token) => ColorPicker::make("{$key}.{$mode}.{$token}")
            ->label($names[$token] ?? $token)
            ->hex(), $tokens);
    }
}
