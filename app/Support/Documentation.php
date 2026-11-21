<?php

namespace App\Support;

use App\Filament\Pages\Documentation as DocumentationPage;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Reads the Markdown files in `/docs` (including sub-folders) and renders
 * them for the admin's Documentation page. Docs are addressed by their path
 * below `/docs` without the `.md` extension (e.g. `user/content/albums`);
 * only files found by scanning the folder can be opened, so request input
 * never reaches the filesystem directly.
 *
 * Nothing about the structure is hard-coded: a doc's place comes from the
 * `order` in its front matter, a folder's place and label from the front
 * matter and first heading of its README.md.
 */
class Documentation
{
    /** The doc opened when none is requested. */
    public const HOME = 'README';

    /** Image types served from `/docs/assets`. */
    public const ASSET_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    private readonly string $path;

    /** @var array<string, array{key: string, folder: string, title: string, order: int|null, file: string}>|null */
    private ?array $docs = null;

    /** @var array{type: string, key: string|null, folder: string, title: string, order: int|null, readme: array<string, mixed>|null, children: list<array<string, mixed>>}|null */
    private ?array $tree = null;

    public function __construct(?string $path = null)
    {
        $this->path = rtrim($path ?? base_path('docs'), DIRECTORY_SEPARATOR);
    }

    /**
     * All docs, keyed by doc key, in navigation order: a folder's README
     * first, then its docs and sub-folders by `order`, then by title.
     *
     * @return array<string, array{key: string, folder: string, title: string, order: int|null, file: string}>
     */
    public function all(): array
    {
        if ($this->docs === null) {
            $this->docs = [];
            $this->tree = $this->scan($this->path, '') ?? $this->emptyFolder();
            $this->collect($this->tree);
        }

        return $this->docs;
    }

    /**
     * The docs as a tree for the navigation. Each folder carries its README
     * (`key`, `title`, `order`; `key` is null without a README) and its
     * sorted `children`: docs (`type` "doc") and sub-folders (`type` "folder").
     *
     * @return array{type: string, key: string|null, folder: string, title: string, order: int|null, readme: array<string, mixed>|null, children: list<array<string, mixed>>}
     */
    public function navigation(): array
    {
        $this->all();

        return $this->tree;
    }

    /** @return array{key: string, folder: string, title: string, order: int|null, file: string}|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The folders that lead to a doc, from the top: for `user/content/albums`
     * the root, `user` and `user/content`. A folder's README is led to by the
     * folders above it, not by the folder it introduces.
     *
     * @return list<array{type: string, key: string|null, folder: string, title: string, order: int|null, readme: array<string, mixed>|null, children: list<array<string, mixed>>}>
     */
    public function trail(string $key): array
    {
        $doc = $this->find($key);

        if ($doc === null || $key === self::HOME) {
            return [];
        }

        $trail = [];
        $folder = $this->navigation();

        foreach ($this->ancestors($doc) as $path) {
            $folder = collect($folder['children'])->first(fn (array $child): bool => $child['type'] === 'folder' && $child['folder'] === $path);

            if ($folder === null) {
                break;
            }

            $trail[] = $folder;
        }

        return [$this->navigation(), ...$trail];
    }

    /**
     * The docs before and after $key in reading order, within the same guide
     * (top-level folder). The start page has none.
     *
     * @return array{previous: array{key: string, title: string}|null, next: array{key: string, title: string}|null}
     */
    public function neighbours(string $key): array
    {
        $guide = Str::contains($key, '/') ? Str::before($key, '/').'/' : null;
        $keys = $guide === null ? [] : array_values(array_filter(array_keys($this->all()), fn (string $other): bool => str_starts_with($other, $guide)));
        $position = array_search($key, $keys, true);
        $doc = fn (int $at): ?array => isset($keys[$at]) ? Arr::only($this->all()[$keys[$at]], ['key', 'title']) : null;

        return [
            'previous' => $position === false ? null : $doc($position - 1),
            'next' => $position === false ? null : $doc($position + 1),
        ];
    }

    /**
     * The `##` sections of a rendered doc, in order, for the "On this page" list.
     *
     * @return list<array{id: string, title: string}>
     */
    public static function outline(string $html): array
    {
        preg_match_all('/<h2[^>]*>(.*?)<a id="([^"]+)"[^>]*class="heading-permalink"/s', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [
            'id' => html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5),
            'title' => trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5)),
        ], $matches);
    }

    /**
     * Docs that mention every word of $query, best matches first: a match in
     * the title ranks above one in a heading, which ranks above the text. Each
     * hit carries a short plain-text excerpt around the first match in the text.
     *
     * @return list<array{key: string, title: string, excerpt: string|null}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', Str::lower(trim($query))) ?: []));

        if ($words === []) {
            return [];
        }

        return collect($this->all())
            ->map(function (array $doc) use ($words): ?array {
                [, $markdown] = self::frontMatter((string) file_get_contents($doc['file']));
                $text = Str::lower($markdown);

                foreach ($words as $word) {
                    if (! str_contains($text, $word) && ! str_contains(Str::lower($doc['title']), $word)) {
                        return null;
                    }
                }

                $headings = Str::lower(implode("\n", preg_grep('/^#{1,6}\s/', explode("\n", $markdown)) ?: []));
                $score = collect($words)->sum(fn (string $word): int => match (true) {
                    str_contains(Str::lower($doc['title']), $word) => 4,
                    str_contains($headings, $word) => 2,
                    default => 1,
                });

                return [
                    'key' => $doc['key'],
                    'title' => $doc['title'],
                    'excerpt' => $this->excerpt($markdown, $words[0]),
                    'score' => $score,
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $hit): array => Arr::except($hit, 'score'))
            ->values()
            ->all();
    }

    /** Renders a doc to sanitized HTML (front matter removed, raw HTML stripped). */
    public function render(string $key): ?string
    {
        $doc = $this->find($key);

        if ($doc === null) {
            return null;
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'id_prefix' => '',
                'fragment_prefix' => '',
                'symbol' => '#',
                'insert' => 'after',
                'title' => __('Link to this section'),
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);
        $environment->addEventListener(
            DocumentParsedEvent::class,
            fn (DocumentParsedEvent $event) => $this->rewriteLinks($event, $doc['key']),
        );

        [, $markdown] = self::frontMatter((string) file_get_contents($doc['file']));

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }

    /** Absolute path of an image in `/docs/assets`, or null if it is not a servable image. */
    public function assetPath(string $path): ?string
    {
        $assets = realpath($this->path.DIRECTORY_SEPARATOR.'assets');
        $file = realpath($this->path.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$path);

        if ($assets === false || $file === false || ! is_file($file) || ! str_starts_with($file, $assets.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return isset(self::ASSET_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))]) ? $file : null;
    }

    /**
     * Splits a leading front matter block from a doc: `---` lines around
     * simple `key: value` pairs (no nesting, no lists). Keys are lowercased,
     * surrounding quotes are removed from values.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    public static function frontMatter(string $markdown): array
    {
        if (! preg_match('/\A---[ \t]*\R(?:(.*?)\R)?---[ \t]*(?:\R|\z)/s', $markdown, $match)) {
            return [[], $markdown];
        }

        $meta = [];

        foreach (preg_split('/\R/', $match[1] ?? '') ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+)[ \t]*:[ \t]*(.*?)[ \t]*$/', $line, $pair)) {
                $meta[strtolower($pair[1])] = trim($pair[2], '"\'');
            }
        }

        return [$meta, substr($markdown, strlen($match[0]))];
    }

    /**
     * One folder of the tree with its README and its sorted docs and
     * sub-folders, or null when it holds no docs at all (such as `assets`).
     *
     * @return array{type: string, key: string|null, folder: string, title: string, order: int|null, readme: array<string, mixed>|null, children: list<array<string, mixed>>}|null
     */
    private function scan(string $directory, string $folder): ?array
    {
        $readme = null;
        $children = [];

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
            $doc = $this->doc($file, $folder);

            if (strcasecmp(basename($file), 'README.md') === 0) {
                $readme = $doc;
            } else {
                $children[] = ['type' => 'doc', ...$doc];
            }
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $subdirectory) {
            $child = $this->scan($subdirectory, ltrim($folder.'/'.basename($subdirectory), '/'));

            if ($child !== null) {
                $children[] = $child;
            }
        }

        if ($readme === null && $children === []) {
            return null;
        }

        usort($children, fn (array $a, array $b): int => [$a['order'] ?? PHP_INT_MAX, Str::lower($a['title'])]
            <=> [$b['order'] ?? PHP_INT_MAX, Str::lower($b['title'])]);

        return [
            'type' => 'folder',
            'key' => $readme['key'] ?? null,
            'folder' => $folder,
            'title' => $readme['title'] ?? Str::ucfirst(str_replace(['-', '_'], ' ', basename($directory))),
            'order' => $readme['order'] ?? null,
            'readme' => $readme,
            'children' => $children,
        ];
    }

    /** @return array{type: string, key: string|null, folder: string, title: string, order: int|null, readme: null, children: list<array<string, mixed>>} */
    private function emptyFolder(): array
    {
        return ['type' => 'folder', 'key' => null, 'folder' => '', 'title' => '', 'order' => null, 'readme' => null, 'children' => []];
    }

    /** @return array{key: string, folder: string, title: string, order: int|null, file: string} */
    private function doc(string $file, string $folder): array
    {
        [$meta, $markdown] = self::frontMatter((string) file_get_contents($file));
        $name = pathinfo($file, PATHINFO_FILENAME);

        return [
            'key' => ltrim($folder.'/'.$name, '/'),
            'folder' => $folder,
            'title' => $this->titleOf($markdown, $name),
            'order' => isset($meta['order']) && is_numeric($meta['order']) ? (int) $meta['order'] : null,
            'file' => $file,
        ];
    }

    /**
     * Paths of the folders a doc sits in below the root, outermost first,
     * leaving out the folder a README introduces.
     *
     * @param  array{key: string, folder: string}  $doc
     * @return list<string>
     */
    private function ancestors(array $doc): array
    {
        $segments = $doc['folder'] === '' ? [] : explode('/', $doc['folder']);

        if (strcasecmp(basename($doc['key']), 'README') === 0) {
            array_pop($segments);
        }

        return array_map(fn (int $depth): string => implode('/', array_slice($segments, 0, $depth + 1)), array_keys($segments));
    }

    /** Adds a folder's docs to the flat list, in navigation order. */
    private function collect(array $folder): void
    {
        if ($folder['readme'] !== null) {
            $this->docs[$folder['readme']['key']] = $folder['readme'];
        }

        foreach ($folder['children'] as $child) {
            if ($child['type'] === 'folder') {
                $this->collect($child);
            } else {
                $this->docs[$child['key']] = Arr::except($child, 'type');
            }
        }
    }

    /** About a line of plain text around the first body line that contains $word. */
    private function excerpt(string $markdown, string $word): ?string
    {
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s*(#|```)/', $line)) {
                continue;
            }

            // Links and images keep their text; Markdown punctuation is dropped.
            $plain = trim(preg_replace(['/!?\[([^\]]*)\]\([^)]*\)/', '/[*_`>|]+/', '/^\s*([-+]|\d+\.)\s+/', '/\s+/'], ['$1', '', '', ' '], $line) ?? '');
            $position = mb_stripos($plain, $word);

            if ($position === false) {
                continue;
            }

            $start = max(0, $position - 40);
            $excerpt = mb_substr($plain, $start, 120);

            return ($start > 0 ? '…' : '').$excerpt.(mb_strlen($plain) > $start + 120 ? '…' : '');
        }

        return null;
    }

    /**
     * Points relative links between docs at the Documentation page and
     * relative images at the asset route, so docs written for the
     * repository work unchanged inside the admin. Images whose file does not
     * exist yet become a visible "Screenshot missing" note. URLs are relative
     * to the host, so they work whatever address the admin is opened on.
     */
    private function rewriteLinks(DocumentParsedEvent $event, string $currentKey): void
    {
        $base = Str::contains($currentKey, '/') ? Str::beforeLast($currentKey, '/') : '';

        // Collected first: replacing nodes while walking the tree would skip some.
        $nodes = array_filter(
            iterator_to_array($event->getDocument()->iterator(), false),
            fn (Node $node): bool => $node instanceof Link || $node instanceof Image,
        );

        foreach ($nodes as $node) {
            $url = $node->getUrl();

            if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
                continue;
            }

            $fragment = Str::contains($url, '#') ? '#'.Str::after($url, '#') : '';
            $target = $this->normalize($base, Str::before($url, '#'));

            if ($target === null) {
                continue;
            }

            if ($node instanceof Image || str_starts_with($target, 'assets/')) {
                $asset = Str::after($target, 'assets/');

                if ($node instanceof Image && $this->assetPath($asset) === null) {
                    $node->replaceWith($this->missingImage($node));

                    continue;
                }

                $node->setUrl(route('docs.asset', ['path' => $asset], absolute: false));
            } elseif (str_ends_with($target, '.md') && $this->find(Str::beforeLast($target, '.md'))) {
                $node->setUrl(DocumentationPage::getUrl(['doc' => Str::beforeLast($target, '.md')], isAbsolute: false).$fragment);
            }
        }
    }

    /** Placeholder for a screenshot that has not been added to `/docs/assets` yet. */
    private function missingImage(Image $image): Emphasis
    {
        $alt = collect(iterator_to_array($image->iterator(), false))
            ->filter(fn (Node $node): bool => $node instanceof Text)
            ->map(fn (Text $node): string => $node->getLiteral())
            ->implode('');

        $placeholder = new Emphasis;
        $placeholder->data->set('attributes', ['class' => 'og-docs-missing-image']);
        $placeholder->appendChild(new Text($alt !== '' ? __('Screenshot missing: :description', ['description' => $alt]) : __('Screenshot missing')));

        return $placeholder;
    }

    /** Resolves a relative path against a folder below `/docs`; null if it leaves `/docs`. */
    private function normalize(string $base, string $relative): ?string
    {
        $parts = [];

        foreach (explode('/', ltrim($base.'/'.$relative, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /** The first `# ` heading of a doc, or its file name as a fallback. */
    private function titleOf(string $markdown, string $name): string
    {
        if (preg_match('/^#[ \t]+(.+?)[ \t]*#*[ \t]*$/m', $markdown, $match)) {
            return $match[1];
        }

        return $name === 'README' ? 'Overview' : Str::headline($name);
    }
}
