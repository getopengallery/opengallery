<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Navigation\NavigationGroup;
use App\Support\SiteSettings;
use App\Support\Tagging;
use BackedEnum;
use Closure;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → Tags: tagging on or off, who may create tags, what is suggested and the predefined tags. */
class TagSettings extends SettingsPage
{
    protected const FIELDS = [
        'tagging_enabled' => [SiteSettings::TAGGING, 'Tagging'],
        'allow_custom_tags' => [SiteSettings::TAGS_ALLOW_CUSTOM, 'Allow new tags'],
        'global_suggestions' => [SiteSettings::TAGS_GLOBAL_SUGGESTIONS, 'Global tag suggestions'],
        'predefined_tags' => [SiteSettings::TAGS_PREDEFINED, 'Predefined tags'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 55;

    protected static ?string $slug = 'tags';

    public static function getNavigationLabel(): string
    {
        return __('Tags');
    }

    protected function formValues(): array
    {
        return [
            'tagging_enabled' => SiteSettings::taggingEnabled(),
            'allow_custom_tags' => SiteSettings::allowsCustomTags(),
            'global_suggestions' => SiteSettings::globalTagSuggestions(),
            'predefined_tags' => SiteSettings::predefinedTags(),
        ];
    }

    protected function formSections(): array
    {
        $taggingOn = fn (Get $get): bool => (bool) $get('tagging_enabled');

        return [
            Section::make(__('Tagging'))
                ->schema([
                    Toggle::make('tagging_enabled')
                        ->label(__('Use tags'))
                        ->helperText(__('People can tag albums and photos, and the search on the site also finds them by tag. When off, tags are hidden and not searched, but they are kept.'))
                        ->live(),
                ]),

            Section::make(__('Creating and suggesting tags'))
                ->visible($taggingOn)
                ->schema([
                    Toggle::make('allow_custom_tags')
                        ->label(__('Allow new tags'))
                        ->helperText(__('People can create their own tags besides the predefined ones. When off, only predefined tags can be added; tags already on an album or photo stay.'))
                        ->live(),
                    Toggle::make('global_suggestions')
                        ->label(__('Suggest tags from everyone'))
                        ->helperText(__('Suggests every tag in use, so fewer variants of the same tag appear. Tag names from private albums are then visible to everyone who can tag. When off, people are only offered predefined tags, their own tags and tags on albums and photos they can open.'))
                        ->visible(fn (Get $get): bool => (bool) $get('allow_custom_tags')),
                ]),

            Section::make(__('Predefined tags'))
                ->description(__('Offered to everyone who tags albums or photos.'))
                ->visible($taggingOn)
                ->schema([
                    TagsInput::make('predefined_tags')
                        ->label(__('Predefined tags'))
                        ->hiddenLabel()
                        ->color('gray')
                        ->placeholder(__('Add a tag'))
                        ->splitKeys(['Enter', ','])
                        ->rule('max:'.Tagging::MAX_PREDEFINED)
                        ->nestedRecursiveRules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (($problem = Tagging::nameProblem($value)) !== null) {
                                    $fail($problem);
                                }
                            },
                        ])
                        ->helperText(__('Press Enter or type a comma after each tag. Removing one here does not take it off albums and photos.')),
                ]),
        ];
    }

    protected function prepareValue(string $field, mixed $value): mixed
    {
        return $field === 'predefined_tags' ? Tagging::uniqueNames((array) $value) : $value;
    }

    protected function afterFieldsStored(array $data): void
    {
        if (array_key_exists('predefined_tags', $data)) {
            Tagging::applyPredefinedSpelling();
        }
    }

    protected function afterSettingsApplied(): void
    {
        if (array_key_exists('predefined_tags', $this->data ?? [])) {
            $this->data['predefined_tags'] = SiteSettings::predefinedTags();
        }
    }
}
