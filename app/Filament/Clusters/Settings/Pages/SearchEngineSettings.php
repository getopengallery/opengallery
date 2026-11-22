<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Navigation\NavigationGroup;
use App\Support\SearchEngines;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Settings → Search engines: search results, link previews, crawlers and site verification. */
class SearchEngineSettings extends SettingsPage
{
    protected const FIELDS = [
        'description' => [SiteSettings::DESCRIPTION, 'Site description'],
        'search_indexing' => [SiteSettings::SEARCH_INDEXING, 'Search engines'],
        'block_ai_crawlers' => [SiteSettings::BLOCK_AI_CRAWLERS, 'Block AI crawlers'],
        'robots_extra' => [SiteSettings::ROBOTS_EXTRA, 'Extra robots.txt rules'],
        'google_verification' => [SiteSettings::GOOGLE_VERIFICATION, 'Google Search Console'],
        'bing_verification' => [SiteSettings::BING_VERIFICATION, 'Bing Webmaster Tools'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'search-engines';

    public static function getNavigationLabel(): string
    {
        return __('Search engines');
    }

    protected function formValues(): array
    {
        return [
            'description' => SiteSettings::description(),
            'search_indexing' => SiteSettings::searchIndexing(),
            'block_ai_crawlers' => SiteSettings::blocksAiCrawlers(),
            'robots_extra' => SiteSettings::robotsExtra(),
            'google_verification' => SiteSettings::googleVerification(),
            'bing_verification' => SiteSettings::bingVerification(),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make(__('Search results'))
                ->description(__('How the site appears in search results and in the previews that messengers and social networks show for shared links.'))
                ->schema([
                    Textarea::make('description')
                        ->label(__('Site description'))
                        ->rows(3)
                        ->maxLength(300)
                        ->columnSpanFull()
                        ->helperText(__('One or two sentences about the site. Shown below its name in search results and link previews.')),
                    Toggle::make('search_indexing')
                        ->label(__('Let search engines list the site'))
                        ->helperText(__('Search engines then find the start page, public albums and published pages through a sitemap. Unlisted, protected and members-only content is never listed. When off, the site asks search engines to stay away; link previews keep working.'))
                        ->live()
                        ->columnSpanFull(),
                    Text::make(fn (): string => __('Sitemap: :url', ['url' => route('search.sitemap')]))
                        ->visible(fn (Get $get): bool => (bool) $get('search_indexing'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Crawlers'))
                ->schema([
                    Toggle::make('block_ai_crawlers')
                        ->label(__('Ask AI crawlers to stay away'))
                        ->helperText(__('Search engines may still list the site, but crawlers that collect photos and text for AI models (from OpenAI, Anthropic, Google, Apple, Meta, Perplexity and others) are asked not to. Well-behaved crawlers respect this; it cannot stop the others.'))
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('search_indexing'))
                        ->columnSpanFull(),
                    Textarea::make('robots_extra')
                        ->label(__('Extra robots.txt rules'))
                        ->rows(4)
                        ->maxLength(5000)
                        ->live(onBlur: true)
                        ->extraInputAttributes(['class' => 'font-mono', 'spellcheck' => 'false'])
                        ->placeholder("User-agent: Example-Bot\nDisallow: /")
                        ->helperText(__('Optional. Added to the robots.txt as written, for example to keep out one more crawler. Most sites need nothing here.'))
                        ->columnSpanFull(),
                    // Worked out from the form on every update, so it follows the choices above (and fits its text).
                    TextEntry::make('robots_preview')
                        ->label(__('robots.txt'))
                        ->state(fn (Get $get): string => SearchEngines::robotsTxt(
                            (bool) $get('search_indexing'),
                            (bool) $get('block_ai_crawlers'),
                            (string) $get('robots_extra'),
                        ))
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<pre class="og-code-preview">'.e($state).'</pre>'))
                        ->belowContent(fn (): string => __('What search engines and other crawlers read at :url. It is written from the choices above.', ['url' => route('search.robots')]))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Site verification'))
                ->columns(2)
                ->visible(fn (Get $get): bool => (bool) $get('search_indexing'))
                ->schema([
                    TextInput::make('google_verification')
                        ->label(__('Google Search Console verification code'))
                        ->maxLength(200)
                        ->dehydrateStateUsing(fn (?string $state): ?string => self::verificationCode($state))
                        ->helperText(__('Choose "HTML tag" as the verification method and paste the tag or its code.')),
                    TextInput::make('bing_verification')
                        ->label(__('Bing Webmaster Tools verification code'))
                        ->maxLength(200)
                        ->dehydrateStateUsing(fn (?string $state): ?string => self::verificationCode($state))
                        ->helperText(__('Choose "HTML Meta Tag" as the verification method and paste the tag or its code.')),
                ]),
        ];
    }

    /** The code from a pasted verification meta tag (or the code itself). */
    public static function verificationCode(?string $state): ?string
    {
        $state = trim((string) $state);

        if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $state, $match)) {
            $state = trim($match[1]);
        }

        return $state !== '' ? $state : null;
    }
}
