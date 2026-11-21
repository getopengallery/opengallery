<?php

namespace App\Filament\Forms\Components;

use App\Support\PeopleAndGroups;
use Closure;
use Filament\Forms\Components\Select;

/**
 * One picker for people and groups. Its state is a list of keys ("user:12",
 * "group:3", see PeopleAndGroups); read them with PeopleAndGroups::split(),
 * which drops anything that does not exist. Groups are offered right away,
 * people are only found by searching their name or e-mail address.
 */
class PeopleAndGroupsSelect extends Select
{
    /** @var list<string>|Closure(): list<string> keys that are not offered */
    protected array|Closure $exceptKeys = [];

    /** @var list<string>|Closure(): (list<string>|null)|null when set, only these can be chosen: no directory search */
    protected array|Closure|null $onlyKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->multiple()
            ->searchable()
            ->searchPrompt(__('Type a name or e-mail address'))
            ->options(fn (self $component): array => ($only = $component->getOnlyKeys()) !== null
                ? PeopleAndGroups::labels($only)
                : PeopleAndGroups::offeredGroups($component->getExceptKeys()))
            ->getSearchResultsUsing(fn (self $component, string $search): array => ($only = $component->getOnlyKeys()) !== null
                ? array_filter(PeopleAndGroups::labels($only), fn (string $label) => str_contains(mb_strtolower($label), mb_strtolower(trim($search))))
                : PeopleAndGroups::search($search, $component->getExceptKeys()))
            ->getOptionLabelsUsing(fn (array $values): array => PeopleAndGroups::labels($values));
    }

    /** @param  list<string>|Closure(): list<string>  $keys */
    public function exceptKeys(array|Closure $keys): static
    {
        $this->exceptKeys = $keys;

        return $this;
    }

    /** @param  list<string>|Closure(): (list<string>|null)|null  $keys */
    public function onlyKeys(array|Closure|null $keys): static
    {
        $this->onlyKeys = $keys;

        return $this;
    }

    /** @return list<string>|null */
    public function getOnlyKeys(): ?array
    {
        return $this->evaluate($this->onlyKeys);
    }

    /** @return list<string> */
    public function getExceptKeys(): array
    {
        return $this->evaluate($this->exceptKeys);
    }
}
