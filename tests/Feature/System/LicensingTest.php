<?php

use App\Enums\Role;
use App\Models\Album;
use App\Models\License;
use App\Support\Licensing;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('gallery'));

it('comes with the eight standard licenses, all active and in order', function () {
    $licenses = License::query()->ordered()->take(8)->get();

    expect($licenses->pluck('name')->all())->toBe([
        'All rights reserved', 'CC BY 4.0', 'CC BY-SA 4.0', 'CC BY-ND 4.0',
        'CC BY-NC 4.0', 'CC BY-NC-SA 4.0', 'CC BY-NC-ND 4.0', 'CC0 1.0',
    ])
        ->and($licenses->every->is_active)->toBeTrue()
        ->and(packLicense('CC BY 4.0')->url)->toBe('https://creativecommons.org/licenses/by/4.0/')
        ->and(packLicense('All rights reserved')->url)->toBeNull()
        ->and(License::count())->toBe(8);
});

describe('on upload', function () {
    it('gives a new photo the default license while licensing is on', function () {
        $default = enableLicensing(packLicense('CC BY 4.0'));

        $photo = galleryPhoto(Album::factory()->create());

        expect($photo->fresh()->license_id)->toBe($default->id)
            ->and(Licensing::forNewPhoto())->toBe($default->id);
    });

    it('leaves the license empty while licensing is off, even with a default set', function () {
        enableLicensing(settings: [SiteSettings::LICENSING => false]);

        expect(galleryPhoto(Album::factory()->create())->fresh()->license_id)->toBeNull();
    });

    it('leaves the license empty when the default has been deactivated', function () {
        $default = enableLicensing();
        $default->update(['is_active' => false]);

        expect(Licensing::default())->toBeNull()
            ->and(galleryPhoto(Album::factory()->create())->fresh()->license_id)->toBeNull();
    });
});

describe('choosing a license', function () {
    beforeEach(function () {
        $this->uploader = userWithRole(Role::Uploader);
        $this->default = packLicense('All rights reserved');
        $this->cc = packLicense('CC BY 4.0');
        $this->cc0 = packLicense('CC0 1.0');
    });

    it('lets everyone pick any active license while choosing is on and no list is set', function () {
        enableLicensing($this->default);

        expect(Licensing::canChoose($this->uploader))->toBeTrue()
            ->and(Licensing::choosableFor($this->uploader))->toHaveCount(8)
            ->and(Licensing::allows($this->uploader, $this->cc0->id))->toBeTrue();
    });

    it('offers only the listed licenses and the default to uploaders, and every active one to managers', function () {
        enableLicensing($this->default, [SiteSettings::LICENSES_CHOOSABLE => [$this->cc->id]]);
        $admin = userWithRole(Role::Admin);

        expect(Licensing::choosableFor($this->uploader)->pluck('id')->all())->toBe([$this->default->id, $this->cc->id])
            ->and(Licensing::allows($this->uploader, $this->cc->id))->toBeTrue()
            ->and(Licensing::allows($this->uploader, $this->cc0->id))->toBeFalse()
            ->and(Licensing::choosableFor($admin))->toHaveCount(8)
            ->and(Licensing::allows($admin, $this->cc0->id))->toBeTrue();
    });

    it('leaves out inactive licenses, even when listed, except the one a photo already has', function () {
        enableLicensing($this->default, [SiteSettings::LICENSES_CHOOSABLE => [$this->cc->id, $this->cc0->id]]);
        $this->cc0->update(['is_active' => false]);
        $photo = galleryPhoto(Album::factory()->create());
        $photo->update(['license_id' => $this->cc0->id]);

        expect(Licensing::choosableFor($this->uploader)->pluck('id')->all())->toBe([$this->default->id, $this->cc->id])
            ->and(Licensing::choosableFor(userWithRole(Role::Admin))->pluck('id')->all())->not->toContain($this->cc0->id)
            ->and(Licensing::allows($this->uploader, $this->cc0->id))->toBeFalse()
            ->and(array_keys(Licensing::optionsFor($this->uploader, $photo)))->toContain($this->cc0->id)
            ->and(Licensing::allows($this->uploader, $this->cc0->id, $photo))->toBeTrue();
    });

    it('allows only the default to uploaders while choosing is off, but still anything to managers', function () {
        enableLicensing($this->default, [SiteSettings::LICENSES_ALLOW_CHOICE => false]);
        $admin = userWithRole(Role::Admin);

        expect(Licensing::canChoose($this->uploader))->toBeFalse()
            ->and(Licensing::choosableFor($this->uploader)->pluck('id')->all())->toBe([$this->default->id])
            ->and(Licensing::allows($this->uploader, $this->cc->id))->toBeFalse()
            ->and(Licensing::canChoose($admin))->toBeTrue()
            ->and(Licensing::allows($admin, $this->cc->id))->toBeTrue();
    });

    it('always accepts no license or the one the photo has', function () {
        enableLicensing($this->default, [SiteSettings::LICENSES_ALLOW_CHOICE => false]);
        $photo = galleryPhoto(Album::factory()->create());
        $photo->update(['license_id' => $this->cc->id]);

        expect(Licensing::allows($this->uploader, null))->toBeTrue()
            ->and(Licensing::allows($this->uploader, $this->cc->id, $photo))->toBeTrue()
            ->and(Licensing::allows($this->uploader, $this->cc->id))->toBeFalse();
    });

    it('lets nobody choose while licensing is off', function () {
        enableLicensing($this->default, [SiteSettings::LICENSING => false]);

        expect(Licensing::canChoose($this->uploader))->toBeFalse()
            ->and(Licensing::canChoose(userWithRole(Role::Admin)))->toBeFalse()
            ->and(Licensing::canChoose(null))->toBeFalse();
    });
});

describe('showing a license', function () {
    it('gives the name, title and link of the photo\'s license while licensing is on', function () {
        enableLicensing();
        $photo = galleryPhoto(Album::factory()->create());
        $photo->update(['license_id' => packLicense('CC BY 4.0')->id]);

        expect(Licensing::displayFor($photo->fresh()))->toBe([
            'name' => 'CC BY 4.0',
            'title' => 'Creative Commons Attribution 4.0 International',
            'url' => 'https://creativecommons.org/licenses/by/4.0/',
        ]);
    });

    it('gives nothing while licensing is off or the photo has no license, but keeps the license on the photo', function () {
        $photo = galleryPhoto(Album::factory()->create());
        $photo->update(['license_id' => packLicense('CC0 1.0')->id]);
        enableLicensing(settings: [SiteSettings::LICENSING => false]);

        expect(Licensing::displayFor($photo->fresh()))->toBeNull()
            ->and($photo->fresh()->license_id)->toBe(packLicense('CC0 1.0')->id);

        enableLicensing();
        $photo->update(['license_id' => null]);

        expect(Licensing::displayFor($photo->fresh()))->toBeNull();
    });
});

describe('describing a license', function () {
    it('gives the description with a link to the license text, escaped', function () {
        $license = License::factory()->create(['description' => 'Use <b>freely</b>', 'url' => 'https://example.com/a?x=1&y=2']);

        $html = (string) Licensing::describe($license->id);

        expect($html)->toContain('Use &lt;b&gt;freely&lt;/b&gt;')
            ->toContain('href="https://example.com/a?x=1&amp;y=2"')
            ->toContain('Read the license')
            ->not->toContain('<b>');
    });

    it('falls back to the full name, leaves out the link without a URL and gives nothing for no or an unknown license', function () {
        $titled = License::factory()->create(['title' => 'Studio terms', 'description' => null, 'url' => null]);
        $empty = License::factory()->create(['title' => null, 'description' => null, 'url' => null]);

        expect((string) Licensing::describe($titled->id))->toBe('Studio terms')
            ->and(Licensing::describe($empty->id))->toBeNull()
            ->and(Licensing::describe(null))->toBeNull()
            ->and(Licensing::describe(''))->toBeNull()
            ->and(Licensing::describe(License::max('id') + 100))->toBeNull();
    });

    it('comes with a description for every standard license', function () {
        expect(License::query()->ordered()->take(8)->get()->every(fn (License $license) => filled($license->displayDescription())))->toBeTrue();
    });
});

it('translates the standard texts but shows an admin-written license as written', function () {
    $own = License::factory()->create(['name' => 'Studio terms', 'title' => 'Our terms', 'description' => 'Print only.']);
    app()->setLocale('de');

    expect(packLicense('All rights reserved')->displayName())->toBe('Alle Rechte vorbehalten')
        ->and(packLicense('CC BY 4.0')->displayName())->toBe('CC BY 4.0')
        ->and($own->displayName())->toBe('Studio terms')
        ->and($own->displayTitle())->toBe('Our terms')
        ->and($own->displayDescription())->toBe('Print only.');
});
