<?php

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\TagSettings;
use App\Models\Tag;
use App\Support\SiteSettings;
use Livewire\Livewire;

beforeEach(fn () => actingAsRole(Role::Admin));

it('saves the settings and the predefined tags without duplicates', function () {
    $existing = Tag::factory()->create(['name' => 'pet 1']);

    Livewire::test(TagSettings::class)
        ->fillForm([
            'allow_custom_tags' => true,
            'global_suggestions' => true,
            'predefined_tags' => ['Pet 1', 'PET  1', 'Nature'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::globalTagSuggestions())->toBeTrue()
        ->and(SiteSettings::predefinedTags())->toBe(['Pet 1', 'Nature'])
        ->and($existing->fresh()->name)->toBe('Pet 1');
});

it('rejects an invalid predefined tag', function () {
    Livewire::test(TagSettings::class)
        ->fillForm(['predefined_tags' => ['!!!']])
        ->call('save')
        ->assertHasFormErrors(['predefined_tags.0']);

    expect(SiteSettings::predefinedTags())->toBe([]);
});
