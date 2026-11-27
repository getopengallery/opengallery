<?php

use App\Enums\Role;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Filament\Resources\Licenses\Pages\CreateLicense;
use App\Filament\Resources\Licenses\Pages\EditLicense;
use App\Filament\Resources\Licenses\Pages\ListLicenses;
use App\Models\Album;
use App\Models\License;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin));

    it('lists the licenses with their photo counts and marks the default', function () {
        Storage::fake('gallery');
        $default = enableLicensing(packLicense('CC BY 4.0'));
        $unused = License::factory()->create(['name' => 'Studio terms']);
        galleryPhoto(Album::factory()->create())->update(['license_id' => $default->id]);

        Livewire::test(ListLicenses::class)
            ->assertCanSeeTableRecords(License::all())
            ->assertTableColumnStateSet('photos_count', 1, $default)
            ->assertTableColumnStateSet('photos_count', 0, $unused)
            ->assertSeeHtml('Creative Commons Attribution 4.0 International')
            ->assertSeeHtml(packLicense('CC0 1.0')->description);
    });

    it('creates and edits a license', function () {
        Livewire::test(CreateLicense::class)
            ->fillForm(['name' => 'Studio terms', 'title' => 'Our studio terms', 'description' => 'You may use the photos in print.', 'url' => 'https://example.com/terms', 'sort_order' => 3])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(LicenseResource::getUrl('index'));

        $license = License::where('name', 'Studio terms')->sole();
        expect($license->is_active)->toBeTrue()
            ->and($license->url)->toBe('https://example.com/terms')
            ->and($license->description)->toBe('You may use the photos in print.');

        Livewire::test(ListLicenses::class)->assertSeeHtml('You may use the photos in print.');

        Livewire::test(EditLicense::class, ['record' => $license->id])
            ->fillForm(['name' => 'Studio terms 2', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($license->fresh())->name->toBe('Studio terms 2')->is_active->toBeFalse();
    });

    it('needs a short name and a link that is a URL', function () {
        Livewire::test(CreateLicense::class)
            ->fillForm(['name' => '', 'url' => 'not a url'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'url']);
    });

    it('keeps the default license active', function () {
        $default = enableLicensing();

        Livewire::test(EditLicense::class, ['record' => $default->id])
            ->assertFormFieldIsDisabled('is_active')
            ->assertSee('This is the default license, so it stays active.');

        Livewire::test(EditLicense::class, ['record' => packLicense('CC0 1.0')->id])
            ->assertFormFieldIsEnabled('is_active');
    });

    it('offers deleting only for a license that is neither the default nor used by a photo', function () {
        Storage::fake('gallery');
        $default = enableLicensing();
        $used = License::factory()->create();
        $trashed = License::factory()->create();
        $unused = License::factory()->create();
        galleryPhoto(Album::factory()->create())->update(['license_id' => $used->id]);
        tap(galleryPhoto(Album::factory()->create()))->update(['license_id' => $trashed->id])->delete();

        $list = Livewire::test(ListLicenses::class)
            ->assertActionHidden(TestAction::make('delete')->table($default))
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->assertActionHidden(TestAction::make('delete')->table($trashed))
            ->assertActionVisible(TestAction::make('delete')->table($unused));

        foreach ([$default, $used, $trashed] as $kept) {
            Livewire::test(EditLicense::class, ['record' => $kept->id])->assertActionHidden('delete');
        }
        Livewire::test(EditLicense::class, ['record' => $unused->id])->assertActionVisible('delete');

        $list->callAction(TestAction::make('delete')->table($unused));

        expect(License::find($unused->id))->toBeNull()
            ->and(License::find($default->id))->not->toBeNull()
            ->and(License::find($used->id))->not->toBeNull()
            ->and(License::find($trashed->id))->not->toBeNull();
    });

    it('is also protected by the database: a license a photo has cannot be deleted', function () {
        Storage::fake('gallery');
        $license = License::factory()->create();
        galleryPhoto(Album::factory()->create())->update(['license_id' => $license->id]);

        expect(fn () => $license->delete())->toThrow(QueryException::class);
    });
});

it('is closed to staff who may not manage settings', function (string $page, array $parameters) {
    $this->actingAs(userWithRole(Role::Moderator));

    $this->get(LicenseResource::getUrl($page, $parameters))->assertForbidden();
})->with([
    'list' => ['index', []],
    'create' => ['create', []],
    'edit' => ['edit', fn () => ['record' => packLicense('CC0 1.0')]],
]);
