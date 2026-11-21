<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Navigation\NavigationGroup;
use App\Support\Documentation as Docs;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Renders the Markdown documentation from `/docs` inside the admin. Open to
 * everyone with "Open the admin area", so not to collaborators who only have
 * the Albums section.
 *
 * The navigation shows one guide (top-level folder) at a time, its sections
 * folded except the one with the open doc, and the open doc's `##` sections
 * below its link. Below a wide screen it moves into a drawer opened from a
 * bar that stays at the top while reading.
 */
class Documentation extends Page
{
    protected string $view = 'filament.pages.documentation';

    protected static ?string $slug = 'documentation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Help;

    protected static ?int $navigationSort = 1;

    /** Doc key: path below `/docs` without `.md`. Each doc opened gets a browser history entry. */
    #[Url(history: true)]
    public string $doc = Docs::HOME;

    /** Rendered HTML of the open doc, kept for the request. */
    protected ?string $html = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::AccessAdmin->value) ?? false;
    }

    public function mount(): void
    {
        abort_unless(app(Docs::class)->find($this->doc), 404);
    }

    /** Opens another doc in place, so the contents keep their scroll position. Unknown docs are ignored. */
    public function open(string $key): void
    {
        if (app(Docs::class)->find($key)) {
            $this->doc = $key;
            $this->html = null;
        }
    }

    /** The doc changed from the browser (back/forward): fall back to the start page if it does not exist. */
    public function updatedDoc(): void
    {
        if (! app(Docs::class)->find($this->doc)) {
            $this->doc = Docs::HOME;
        }

        $this->html = null;
    }

    public function getTitle(): string|Htmlable
    {
        return app(Docs::class)->find($this->doc)['title'] ?? __('Documentation');
    }

    public static function getNavigationLabel(): string
    {
        return __('Documentation');
    }

    /**
     * The folders that lead to the open doc, linked to their READMEs.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [];

        foreach (app(Docs::class)->trail($this->doc) as $folder) {
            if ($folder['key'] !== null) {
                $breadcrumbs[static::getUrl(['doc' => $folder['key']])] = $folder['folder'] === '' ? __('Documentation') : $folder['title'];
            }
        }

        return $breadcrumbs;
    }

    /** Rendered (sanitized) HTML of the open doc. */
    public function getContent(): string
    {
        return $this->html ??= app(Docs::class)->render($this->doc) ?? '';
    }

    /**
     * The docs tree: folders (labelled by their README) with their docs and sub-folders, in order.
     *
     * @return array{type: string, key: string|null, folder: string, title: string, order: int|null, readme: array<string, mixed>|null, children: list<array<string, mixed>>}
     */
    public function getNavigation(): array
    {
        return app(Docs::class)->navigation();
    }

    /**
     * The guides (top-level folders) shown one at a time in the navigation.
     *
     * @return list<array<string, mixed>>
     */
    public function getGuides(): array
    {
        return array_values(array_filter($this->getNavigation()['children'], fn (array $item): bool => $item['type'] === 'folder'));
    }

    /** Folder of the guide that holds the open doc; the first guide on the start page. */
    public function getCurrentGuide(): ?string
    {
        $folders = array_column($this->getGuides(), 'folder');
        $guide = explode('/', $this->doc)[0];

        return in_array($guide, $folders, true) && $guide !== $this->doc ? $guide : ($folders[0] ?? null);
    }

    /**
     * The `##` sections of the open doc.
     *
     * @return list<array{id: string, title: string}>
     */
    public function getOutline(): array
    {
        return Docs::outline($this->getContent());
    }

    /**
     * @return array{previous: array{key: string, title: string}|null, next: array{key: string, title: string}|null}
     */
    public function getNeighbours(): array
    {
        return app(Docs::class)->neighbours($this->doc);
    }

    /**
     * Every doc with the guide and section it belongs to, for filtering by title.
     *
     * @return list<array{url: string, title: string, context: string}>
     */
    public function getPages(): array
    {
        $docs = app(Docs::class);

        return collect($docs->all())
            ->map(fn (array $doc): array => [
                'url' => static::getUrl(['doc' => $doc['key']]),
                'title' => $doc['title'],
                'context' => collect($docs->trail($doc['key']))
                    ->reject(fn (array $folder): bool => $folder['folder'] === '')
                    ->pluck('title')
                    ->implode(' / '),
            ])
            ->values()
            ->all();
    }
}
