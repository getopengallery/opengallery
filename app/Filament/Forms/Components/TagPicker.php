<?php

namespace App\Filament\Forms\Components;

use App\Models\Album;
use App\Models\Photo;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Closure;
use Filament\Forms\Components\Field;

/**
 * The tags of an album or photo. Focusing the field opens a dropdown of the
 * existing tags the person may use; typing narrows it, close spellings
 * included, so they can pick one instead of creating a variant. Not there
 * while tagging is off. Saves through App\Support\Tagging, which also
 * enforces the settings.
 */
class TagPicker extends Field
{
    protected string $view = 'filament.forms.tag-picker';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Tags'))
            ->default([])
            ->visible(fn (): bool => SiteSettings::taggingEnabled())
            ->afterStateHydrated(fn (TagPicker $component, Album|Photo|null $record) => $component->state($record ? Tagging::namesOf($record) : []))
            ->dehydrated(false)
            ->rule('array')
            ->rule(fn (Album|Photo|null $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                if (($problem = Tagging::problemWith((array) $value, $record)) !== null) {
                    $fail($problem);
                }
            })
            ->saveRelationshipsUsing(function (TagPicker $component, Album|Photo $record, ?array $state): void {
                Tagging::sync($record, $state ?? [], auth()->user());
                $component->state(Tagging::namesOf($record));
            })
            ->helperText(fn (Album|Photo|null $record): ?string => $this->help($record));
    }

    /** @return list<string> */
    public function getSuggestions(): array
    {
        return Tagging::suggestionsFor(auth()->user());
    }

    public function allowsNewTags(): bool
    {
        return SiteSettings::allowsCustomTags();
    }

    private function help(Album|Photo|null $record): ?string
    {
        if ($record instanceof Photo) {
            $inherited = Tagging::inheritedNames($record, auth()->user());

            return $inherited === [] ? null : __('From its albums: :tags', ['tags' => implode(', ', $inherited)]);
        }

        $inherited = $record ? Tagging::namesFromParents($record, auth()->user()) : [];

        return implode(' ', array_filter([
            __('Photos in this album get these tags too.'),
            $inherited === [] ? null : __('From parent albums: :tags', ['tags' => implode(', ', $inherited)]),
        ]));
    }
}
