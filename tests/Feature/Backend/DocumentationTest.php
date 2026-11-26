<?php

use App\Enums\Role;
use App\Filament\Pages\Documentation as DocumentationPage;
use App\Support\Documentation;
use Livewire\Livewire;

beforeEach(fn () => useFixtureDocs());

it('renders docs without their front matter, with host-relative links and images, and without raw html', function () {
    $docs = app(Documentation::class);

    expect($docs->render('user/albums'))->not->toContain('order')->not->toContain('Not shown')
        ->and($docs->search('Not shown'))->toBe([])
        ->and($docs->render('user/basics/first-steps'))
        ->toContain('href="'.DocumentationPage::getUrl(['doc' => 'user/albums'], isAbsolute: false).'#sharing"')
        ->and($docs->render('README'))
        ->toContain('href="'.DocumentationPage::getUrl(['doc' => 'user/albums'], isAbsolute: false).'#sharing"')
        ->toContain('href="'.DocumentationPage::getUrl(['doc' => 'dev/plugins'], isAbsolute: false).'"')
        ->toContain('href="https://example.com"')
        ->toContain('href="../../.env"')
        ->not->toContain('<script>')
        ->and($docs->render('user/albums'))
        ->toContain('src="/help/assets/upload.png"')
        ->toContain('<em class="og-docs-missing-image">Screenshot missing: Missing shot</em>')
        ->not->toContain('not-there.png')
        ->toContain('id="sharing"')
        ->toContain('href="'.DocumentationPage::getUrl(['doc' => 'README'], isAbsolute: false).'"');
});

describe('as an admin', function () {
    beforeEach(fn () => actingAsRole(Role::Admin));

    it('shows the documentation page, but returns 404 for docs that are not in the docs folder', function () {
        $this->get(DocumentationPage::getUrl(['doc' => 'user/albums'], isAbsolute: false))
            ->assertOk()
            ->assertSee('Albums')
            ->assertSee('Developer guide')
            ->assertSee('Extra notes')
            ->assertSee(DocumentationPage::getUrl(['doc' => 'user/basics/README']), escape: false);

        $this->get(DocumentationPage::getUrl(['doc' => '../composer']))->assertNotFound();
    });

    it('opens another doc in place, ignores docs that do not exist, and shows the folders that lead to the open doc as breadcrumbs', function () {
        $page = Livewire::withQueryParams(['doc' => 'user/albums'])
            ->test(DocumentationPage::class)
            ->assertSee('Sharing')
            ->call('open', 'user/basics/first-steps')
            ->assertSet('doc', 'user/basics/first-steps')
            ->assertSee('See the')
            ->assertDontSee('Back to the');

        expect($page->instance()->getBreadcrumbs())->toBe([
            DocumentationPage::getUrl(['doc' => 'README']) => 'Documentation',
            DocumentationPage::getUrl(['doc' => 'user/README']) => 'User guide',
            DocumentationPage::getUrl(['doc' => 'user/basics/README']) => 'Basics',
        ]);

        $page->call('open', '../composer')
            ->assertSet('doc', 'user/basics/first-steps')
            ->set('doc', 'nope')
            ->assertSet('doc', Documentation::HOME);
    });

    it('serves doc images and nothing outside the assets folder', function () {
        $this->get(route('docs.asset', ['path' => 'upload.png']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->get(route('docs.asset', ['path' => 'notes.txt']))->assertNotFound();
        $this->get(route('docs.asset', ['path' => '../README.md']))->assertNotFound();
    });
});

it('keeps the documentation pages and images from users without admin access', function () {
    $this->actingAs(userWithRole(Role::User));

    $this->get(DocumentationPage::getUrl())->assertForbidden();
    $this->get(route('docs.asset', ['path' => 'upload.png']))->assertForbidden();
});
