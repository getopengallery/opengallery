<?php

use App\Enums\Role;
use App\Filament\Pages\Documentation as DocumentationPage;
use App\Support\Documentation;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('orders docs and folders by their front matter, unordered ones last by title', function () {
    $docs = useFixtureDocs();
    $tree = $docs->navigation();
    $titles = fn (array $folder): array => array_column($folder['children'], 'title');

    expect($tree['key'])->toBe('README')
        ->and($tree['title'])->toBe('Fixture docs')
        ->and($titles($tree))->toBe(['User guide', 'Developer guide'])
        ->and($titles($tree['children'][0]))->toBe(['Zebras', 'Basics', 'Albums', 'Appendix', 'Extra notes'])
        ->and(array_keys($docs->all()))->toBe([
            'README',
            'user/README', 'user/zebra', 'user/basics/README', 'user/basics/first-steps', 'user/albums', 'user/appendix', 'user/extra-notes/note',
            'dev/README', 'dev/plugins',
        ]);
});

it('labels folders by their README heading, or by their name without one', function () {
    $user = useFixtureDocs()->navigation()['children'][0];
    [, $basics, , , $notes] = $user['children'];

    expect($basics)->toMatchArray(['type' => 'folder', 'key' => 'user/basics/README', 'title' => 'Basics', 'order' => 15])
        ->and($notes)->toMatchArray(['type' => 'folder', 'key' => null, 'title' => 'Extra notes', 'order' => null])
        ->and($notes['children'][0]['key'])->toBe('user/extra-notes/note');
});

it('reads simple front matter and leaves docs without it untouched', function () {
    expect(Documentation::frontMatter("---\norder: \"20\"\nLayout: wide\n---\n# Doc\n"))->toBe([['order' => '20', 'layout' => 'wide'], "# Doc\n"])
        ->and(Documentation::frontMatter("---\n---\n# Doc"))->toBe([[], '# Doc'])
        ->and(Documentation::frontMatter("# Doc\n\n---\n\nText"))->toBe([[], "# Doc\n\n---\n\nText"]);
});

it('hides front matter on the page and in search', function () {
    $docs = useFixtureDocs();

    expect($docs->render('user/albums'))->not->toContain('order')->not->toContain('Not shown')
        ->and($docs->search('Not shown'))->toBe([])
        ->and($docs->render('user/basics/first-steps'))
        ->toContain('href="'.DocumentationPage::getUrl(['doc' => 'user/albums'], isAbsolute: false).'#sharing"');
});

it('rewrites relative links and images to host-relative urls and strips raw html', function () {
    $docs = useFixtureDocs();

    expect($docs->render('README'))
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

it('shows the documentation page to admin users only', function () {
    useFixtureDocs();

    $this->actingAs(userWithRole())
        ->get(DocumentationPage::getUrl(['doc' => 'user/albums'], isAbsolute: false))
        ->assertOk()
        ->assertSee('Albums')
        ->assertSee('Developer guide')
        ->assertSee('Extra notes')
        ->assertSee(DocumentationPage::getUrl(['doc' => 'user/basics/README']), escape: false);

    $this->actingAs(userWithRole(Role::User))
        ->get(DocumentationPage::getUrl())
        ->assertForbidden();
});

it('leads to a doc through its folders, not through the folder a README introduces', function () {
    $docs = useFixtureDocs();
    $trail = fn (string $key): array => array_column($docs->trail($key), 'title');

    expect($trail('user/basics/first-steps'))->toBe(['Fixture docs', 'User guide', 'Basics'])
        ->and($trail('user/basics/README'))->toBe(['Fixture docs', 'User guide'])
        ->and($trail('dev/plugins'))->toBe(['Fixture docs', 'Developer guide'])
        ->and($trail('README'))->toBe([]);
});

it('links to the previous and next doc within the same guide only', function () {
    $docs = useFixtureDocs();
    $keys = fn (string $key): array => array_map(fn (?array $doc): ?string => $doc['key'] ?? null, $docs->neighbours($key));

    expect($keys('user/albums'))->toBe(['previous' => 'user/basics/first-steps', 'next' => 'user/appendix'])
        ->and($keys('user/README'))->toBe(['previous' => null, 'next' => 'user/zebra'])
        ->and($keys('user/extra-notes/note'))->toBe(['previous' => 'user/appendix', 'next' => null])
        ->and($keys('dev/README'))->toBe(['previous' => null, 'next' => 'dev/plugins'])
        ->and($keys('README'))->toBe(['previous' => null, 'next' => null]);
});

it('lists the sections of a doc for the contents', function () {
    $docs = useFixtureDocs();

    expect(Documentation::outline($docs->render('user/albums')))->toBe([['id' => 'sharing', 'title' => 'Sharing']])
        ->and(Documentation::outline($docs->render('user/zebra')))->toBe([]);
});

it('opens another doc in place and ignores docs that do not exist', function () {
    useFixtureDocs();
    $this->actingAs(userWithRole());

    Livewire::withQueryParams(['doc' => 'user/albums'])
        ->test(DocumentationPage::class)
        ->assertSee('Sharing')
        ->call('open', 'user/basics/first-steps')
        ->assertSet('doc', 'user/basics/first-steps')
        ->assertSee('See the')
        ->assertDontSee('Back to the')
        ->call('open', '../composer')
        ->assertSet('doc', 'user/basics/first-steps')
        ->set('doc', 'nope')
        ->assertSet('doc', Documentation::HOME);
});

it('shows the folders that lead to the open doc as breadcrumbs', function () {
    useFixtureDocs();
    $this->actingAs(userWithRole());

    $page = Livewire::withQueryParams(['doc' => 'user/basics/first-steps'])->test(DocumentationPage::class)->instance();

    expect($page->getBreadcrumbs())->toBe([
        DocumentationPage::getUrl(['doc' => 'README']) => 'Documentation',
        DocumentationPage::getUrl(['doc' => 'user/README']) => 'User guide',
        DocumentationPage::getUrl(['doc' => 'user/basics/README']) => 'Basics',
    ]);
});

it('returns 404 for docs that are not in the docs folder', function () {
    useFixtureDocs();

    $this->actingAs(userWithRole())
        ->get(DocumentationPage::getUrl(['doc' => '../composer']))
        ->assertNotFound();
});

it('serves doc images to admin users only and nothing outside the assets folder', function () {
    useFixtureDocs();

    $this->actingAs(userWithRole())->get(route('docs.asset', ['path' => 'upload.png']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
    $this->actingAs(userWithRole())->get(route('docs.asset', ['path' => 'notes.txt']))->assertNotFound();
    $this->actingAs(userWithRole())->get(route('docs.asset', ['path' => '../README.md']))->assertNotFound();

    $this->actingAs(userWithRole(Role::User))->get(route('docs.asset', ['path' => 'upload.png']))->assertForbidden();
});

it('ships a docs index that renders', function () {
    expect(app(Documentation::class)->render(Documentation::HOME))->toContain('<h1');
});

it('gives every shipped doc an order, except the index', function () {
    $unordered = collect(app(Documentation::class)->all())
        ->filter(fn (array $doc): bool => $doc['key'] !== Documentation::HOME && $doc['order'] === null)
        ->keys()
        ->all();

    expect($unordered)->toBe([]);
});

it('has no broken links, anchors or images in the shipped docs', function () {
    $docs = app(Documentation::class);
    $root = base_path('docs');
    $broken = [];

    foreach ($docs->all() as $doc) {
        [, $markdown] = Documentation::frontMatter((string) file_get_contents($doc['file']));
        $markdown = preg_replace('/^```.*?^```/ms', '', $markdown);
        $folder = dirname($doc['file']);

        foreach (explode("\n", $markdown) as $line) {
            $line = preg_replace('/`[^`]*`/', '', $line);
            preg_match_all('/(!?)\[[^\]]*\]\(([^)\s]+)[^)]*\)/', $line, $links, PREG_SET_ORDER);

            foreach ($links as [, $image, $url]) {
                if (preg_match('/^([a-z][a-z0-9+.-]*:|\/)/i', $url)) {
                    continue;
                }

                [$path, $fragment] = array_pad(explode('#', $url, 2), 2, '');
                $file = $path === '' ? $doc['file'] : realpath($folder.'/'.$path);

                if ($file === false || ! str_starts_with($file, $root)) {
                    if (! ($image && str_contains($line, '<!-- TODO'))) {
                        $broken[] = "{$doc['key']}: {$url}";
                    }

                    continue;
                }

                if ($fragment !== '' && str_ends_with($file, '.md')) {
                    $key = Str::beforeLast(Str::after($file, $root.'/'), '.md');

                    if (! str_contains((string) $docs->render($key), 'id="'.$fragment.'"')) {
                        $broken[] = "{$doc['key']}: {$url} (no such section)";
                    }
                }
            }
        }
    }

    expect($broken)->toBe([]);
});
