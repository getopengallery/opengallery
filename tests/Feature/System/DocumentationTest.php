<?php

use App\Support\Documentation;
use Illuminate\Support\Str;

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
