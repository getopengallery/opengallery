<?php

use App\Enums\Role;
use App\Extensions\ThemeManager;
use App\Filament\Actions\ActionIcons;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Models\User;
use App\Support\AdminTheme;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::SuperAdmin->value));
});

it('gives Filament\'s standard, trash and custom actions one icon and colour per kind', function (Closure $make, $icon, ?string $color) {
    $action = $make();

    expect($action->getIcon())->toBe($icon);

    if ($color !== null) {
        expect($action->getColor())->toBe($color);
    }
})->with([
    'edit' => [fn () => EditAction::make(), ActionIcons::EDIT, null],
    'view' => [fn () => ViewAction::make(), ActionIcons::VIEW, null],
    'delete' => [fn () => DeleteAction::make(), ActionIcons::DELETE, 'danger'],
    'delete bulk' => [fn () => DeleteBulkAction::make(), ActionIcons::DELETE, 'danger'],
    'force delete' => [fn () => ForceDeleteAction::make(), ActionIcons::DELETE, 'danger'],
    'force delete bulk' => [fn () => ForceDeleteBulkAction::make(), ActionIcons::DELETE, 'danger'],
    'restore' => [fn () => RestoreAction::make(), ActionIcons::RESTORE, 'gray'],
    'restore bulk' => [fn () => RestoreBulkAction::make(), ActionIcons::RESTORE, 'gray'],
    'detach' => [fn () => DetachAction::make(), ActionIcons::REMOVE, 'gray'],
    'detach bulk' => [fn () => DetachBulkAction::make(), ActionIcons::REMOVE, 'gray'],
    'an icon set on the action itself wins over the default' => [fn () => DeleteAction::make()->icon(ActionIcons::VIEW), ActionIcons::VIEW, 'danger'],
    'trash: move to trash' => [fn () => TrashActions::moveToTrash(), ActionIcons::DELETE, null],
    'trash: move to trash bulk' => [fn () => TrashActions::moveToTrashBulk(), ActionIcons::DELETE, null],
    'trash: delete permanently' => [fn () => TrashActions::deletePermanently(), ActionIcons::DELETE, null],
    'trash: delete permanently bulk' => [fn () => TrashActions::deletePermanentlyBulk(), ActionIcons::DELETE, null],
    'trash: restore' => [fn () => TrashActions::restore(), ActionIcons::RESTORE, 'gray'],
    'trash: restore bulk' => [fn () => TrashActions::restoreBulk(), ActionIcons::RESTORE, 'gray'],
    'a custom remove action' => [fn () => ActionIcons::remove(Action::make('remove')), ActionIcons::REMOVE, 'gray'],
]);

it('shows the label of every icon-only button as its tooltip', function () {
    expect(Action::make('edit')->label('Edit')->iconButton()->getTooltip())->toBe('Edit')
        ->and(Action::make('edit')->label('Edit')->getTooltip())->toBeNull()
        ->and(Action::make('edit')->label('Edit')->tooltip('Change it')->iconButton()->getTooltip())->toBe('Change it');
});

it('colours the admin from the theme settings', function (?array $options, string $primary, array $grays, ?bool $neutralGrey) {
    if ($options !== null) {
        app(ThemeManager::class)->saveOptions('default', $options);
    }

    expect(AdminTheme::primary()[600])->toBe(expectedShade($primary));

    foreach ($grays as $shade => $hex) {
        expect(AdminTheme::gray()[$shade])->toBe(Color::convertToOklch($hex));
    }

    if ($neutralGrey !== null) {
        expect(AdminTheme::gray()[500] === Color::Neutral[500])->toBe($neutralGrey);
    }
})->with(function () {
    $custom = ['palette' => 'neutral', 'colors' => ['light' => ['primary' => '#B4235A'], 'dark' => ['canvas' => '#101010']]];

    return [
        'the theme colours by default' => [null, '#1A1A1A', [950 => '#1B1B1B'], true],
        'the palette chosen in the theme settings, with its warm grey tint' => [['palette' => 'exp'], '#FF5C35', [950 => '#181210', 900 => '#221916'], false],
        'custom colours turned off' => [[...$custom, 'custom_colors' => false], '#1A1A1A', [], null],
        'custom colours turned on' => [[...$custom, 'custom_colors' => true], '#B4235A', [950 => '#101010'], null],
    ];
});

describe('the trash count', function () {
    it('is counted once per request, however often the list asks', function () {
        Album::factory()->create()->delete();
        TrashActions::count(AlbumResource::class);
        DB::enableQueryLog();

        $count = TrashActions::count(AlbumResource::class);

        expect($count)->toBe(1);
        expect(DB::getQueryLog())->toBeEmpty();
    });

    it('is counted again when something goes to or leaves the trash', function (bool $trashed, Closure $change, int $expected) {
        $album = Album::factory()->create(['deleted_at' => $trashed ? now() : null]);
        TrashActions::count(AlbumResource::class);

        $change($album);

        expect(TrashActions::count(AlbumResource::class))->toBe($expected);
    })->with([
        'moved to the trash' => [false, fn (Album $album) => $album->delete(), 1],
        'restored' => [true, fn (Album $album) => $album->restore(), 0],
        'deleted permanently' => [true, fn (Album $album) => $album->forceDelete(), 0],
    ]);
});
