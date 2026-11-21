<?php

namespace App\Filament\GlobalSearch;

use App\Enums\Permission;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Pages\Documentation as DocumentationPage;
use App\Support\Documentation;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The admin's search field: finds admin pages (everything in the navigation
 * the user may open, settings pages by their fields, plus "New …" shortcuts), records of every searchable
 * resource (albums, media, pages, users, groups, roles; each resource decides
 * what it searches and whom it shows) and the documentation.
 */
class AdminSearchProvider extends DefaultGlobalSearchProvider
{
    /** Category names (English keys, translated when shown). */
    public const PAGES_CATEGORY = 'Admin';

    public const DOCS_CATEGORY = 'Documentation';

    public function getResults(string $query): ?GlobalSearchResults
    {
        // Collaborators only have the Albums section (AdminPanelProvider hides the field for them).
        if (! auth()->user()?->can(Permission::AccessAdmin->value)) {
            return GlobalSearchResults::make();
        }

        $records = parent::getResults($query) ?? GlobalSearchResults::make();
        $results = GlobalSearchResults::make();

        $pages = $this->pageResults($query);

        if ($pages !== []) {
            $results->category(__(self::PAGES_CATEGORY), $pages);
        }

        foreach ($records->getCategories() as $name => $categoryResults) {
            $results->category($name, $categoryResults);
        }

        $docs = $this->docResults($query);

        if ($docs !== []) {
            $results->category(__(self::DOCS_CATEGORY), $docs);
        }

        return $results;
    }

    /**
     * Navigation entries (only those the user can see) and "New …" actions
     * whose label or group contains every word of the query.
     *
     * @return list<GlobalSearchResult>
     */
    private function pageResults(string $query): array
    {
        $results = [];

        foreach (Filament::getNavigation() as $group) {
            if (! $group instanceof NavigationGroup) {
                continue;
            }

            $groupLabel = $this->text($group->getLabel());

            foreach ($group->getItems() as $item) {
                foreach ([$item, ...$item->getChildItems()] as $entry) {
                    if ($entry instanceof NavigationItem && $entry->isVisible() && filled($entry->getUrl())) {
                        $label = $this->text($entry->getLabel());

                        if ($this->matches($query, $label.' '.$groupLabel)) {
                            $results[$entry->getUrl()] ??= new GlobalSearchResult(
                                title: $label,
                                url: $entry->getUrl(),
                                details: filled($groupLabel) ? [$groupLabel] : [],
                            );
                        }
                    }
                }
            }
        }

        foreach (SettingsCluster::getClusteredComponents() as $page) {
            if (is_subclass_of($page, SettingsPage::class) && $page::canAccess() && $this->matches($query, $page::searchText())) {
                $results[$page::getUrl()] ??= new GlobalSearchResult(
                    title: $this->text($page::getNavigationLabel()),
                    url: $page::getUrl(),
                    details: [__('Settings')],
                );
            }
        }

        foreach (Filament::getResources() as $resource) {
            if ($this->canCreateFromSearch($resource)) {
                $label = __('New :item', ['item' => $resource::getModelLabel()]);

                if ($this->matches($query, $label.' create add new '.__('Create').' '.__('Add').' '.$resource::getPluralModelLabel())) {
                    $results[$resource::getUrl('create')] ??= new GlobalSearchResult(
                        title: Str::ucfirst($label),
                        url: $resource::getUrl('create'),
                        details: [$this->text($resource::getNavigationGroup()) ?: $resource::getPluralModelLabel()],
                    );
                }
            }
        }

        return array_values($results);
    }

    /** @return list<GlobalSearchResult> */
    private function docResults(string $query): array
    {
        if (! DocumentationPage::canAccess()) {
            return [];
        }

        return array_map(
            fn (array $hit): GlobalSearchResult => new GlobalSearchResult(
                title: $hit['title'],
                url: DocumentationPage::getUrl(['doc' => $hit['key']]),
                details: filled($hit['excerpt']) ? [$hit['excerpt']] : [],
            ),
            app(Documentation::class)->search($query),
        );
    }

    /** @param  class-string<resource>  $resource */
    private function canCreateFromSearch(string $resource): bool
    {
        return $resource::hasPage('create') && $resource::canAccess() && $resource::canCreate();
    }

    /** Whether $haystack contains every word of $query (case-insensitive). */
    private function matches(string $query, string $haystack): bool
    {
        $haystack = Str::lower($haystack);
        $words = array_filter(preg_split('/\s+/', Str::lower(trim($query))) ?: []);

        return $words !== [] && collect($words)->every(fn (string $word): bool => str_contains($haystack, $word));
    }

    private function text(string|Htmlable|UnitEnum|null $value): string
    {
        return match (true) {
            $value instanceof Htmlable => trim(strip_tags($value->toHtml())),
            $value instanceof HasLabel => (string) $value->getLabel(),
            $value instanceof UnitEnum => $value->name,
            default => (string) $value,
        };
    }
}
