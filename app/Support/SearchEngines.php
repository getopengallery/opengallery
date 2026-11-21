<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * robots.txt and sitemap.xml, both generated from the site's content and
 * Admin → Settings → Search engines (SiteSettings::searchIndexing()).
 *
 * The sitemap only lists what a visitor without an account may open and a
 * search engine may index: the start page, public albums without a password
 * and published pages that are not for members only. Unlisted albums never
 * appear: their share links are secrets.
 *
 * With indexing turned off, robots.txt keeps search engines out, but still
 * lets the apps that build link previews in, so shared links keep showing a
 * title and photo; every page also says "noindex" (App\Support\PageMeta).
 * With indexing on, AI crawlers can be asked to stay away on their own
 * (SiteSettings::blocksAiCrawlers()), and the admin's own lines
 * (SiteSettings::robotsExtra()) are added as written.
 */
class SearchEngines
{
    /** Most addresses one sitemap file may list. */
    public const SITEMAP_LIMIT = 50000;

    /** Crawlers that only build link previews; let in even when indexing is off. */
    public const PREVIEW_CRAWLERS = [
        'facebookexternalhit',
        'Facebot',
        'Twitterbot',
        'LinkedInBot',
        'Slackbot',
        'Discordbot',
        'TelegramBot',
        'WhatsApp',
        'SkypeUriPreview',
        'Mastodon',
    ];

    /**
     * Crawlers that collect pages and images for AI models (training data,
     * AI answers). Asked to stay away while SiteSettings::blocksAiCrawlers()
     * is on; normal search engines (Googlebot, Bingbot, ...) are not affected.
     */
    public const AI_CRAWLERS = [
        'GPTBot',
        'ChatGPT-User',
        'OAI-SearchBot',
        'ClaudeBot',
        'Claude-User',
        'Claude-SearchBot',
        'anthropic-ai',
        'Google-Extended',
        'Applebot-Extended',
        'CCBot',
        'PerplexityBot',
        'Perplexity-User',
        'Bytespider',
        'meta-externalagent',
        'FacebookBot',
        'Amazonbot',
        'cohere-ai',
        'cohere-training-data-crawler',
        'Diffbot',
        'ImagesiftBot',
        'Omgilibot',
        'Timpibot',
        'AI2Bot',
        'YouBot',
    ];

    /** Paths that are never worth crawling (admin, account pages, background jobs). */
    public const PRIVATE_PATHS = ['/admin', '/profile', '/my-albums', '/uploads', '/cron/', '/background-jobs'];

    /** What Laravel's default public/robots.txt contained; removed on upgrade so the generated one is served. */
    public const LEGACY_ROBOTS_FILE = "User-agent: *\nDisallow:\n";

    /**
     * The robots.txt for the given choices; null = as saved in Admin → Settings
     * (the settings page passes the form's values for its preview).
     */
    public static function robotsTxt(?bool $indexing = null, ?bool $blockAi = null, ?string $extra = null): string
    {
        $indexing ??= SiteSettings::searchIndexing();
        $blockAi ??= SiteSettings::blocksAiCrawlers();
        $extra = trim(str_replace("\r\n", "\n", $extra ?? (string) SiteSettings::robotsExtra()));

        $groups = [];

        if (! $indexing) {
            $groups[] = [...self::agents(self::PREVIEW_CRAWLERS), 'Allow: /'];
            $groups[] = ['User-agent: *', 'Disallow: /'];
        } else {
            if ($blockAi) {
                $groups[] = [...self::agents(self::AI_CRAWLERS), 'Disallow: /'];
            }
            $groups[] = ['User-agent: *', ...array_map(fn (string $path) => 'Disallow: '.$path, self::PRIVATE_PATHS)];
        }

        if ($extra !== '') {
            $groups[] = [$extra];
        }

        if ($indexing) {
            $groups[] = ['Sitemap: '.route('search.sitemap')];
        }

        return implode("\n\n", array_map(fn (array $lines) => implode("\n", $lines), $groups))."\n";
    }

    /**
     * @param  list<string>  $crawlers
     * @return list<string>
     */
    private static function agents(array $crawlers): array
    {
        return array_map(fn (string $crawler) => 'User-agent: '.$crawler, $crawlers);
    }

    /**
     * Addresses for sitemap.xml, newest change first within each kind.
     *
     * @return Collection<int, array{url: string, modified: ?Carbon}>
     */
    public static function sitemapEntries(): Collection
    {
        $entries = collect();

        if (SiteSettings::publicAlbumsEnabled() || SiteSettings::guestHome() === 'landing') {
            $entries->push(['url' => route('home'), 'modified' => null]);
        }

        if (SiteSettings::publicAlbumsEnabled()) {
            Album::query()
                ->listableFor(null)
                ->whereNull('password')
                ->whereNull('system_album')
                ->latest('updated_at')
                ->limit(self::SITEMAP_LIMIT)
                ->get(['id', 'slug', 'updated_at'])
                ->each(fn (Album $album) => $entries->push(['url' => route('albums.show', $album), 'modified' => $album->updated_at]));
        }

        Page::query()
            ->published()
            ->where('members_only', false)
            ->latest('updated_at')
            ->limit(self::SITEMAP_LIMIT)
            ->get(['id', 'slug', 'updated_at'])
            ->each(fn (Page $page) => $entries->push(['url' => route('pages.show', $page), 'modified' => $page->updated_at]));

        return $entries->take(self::SITEMAP_LIMIT)->values();
    }

    /**
     * Removes the static public/robots.txt that installs from before the
     * generated one shipped with, as long as nobody changed it; a web server
     * serves that file instead of asking the application. Returns whether a
     * static file is left in the way.
     */
    public static function removeLegacyRobotsFile(?string $publicPath = null): bool
    {
        $file = ($publicPath ?? public_path()).DIRECTORY_SEPARATOR.'robots.txt';

        if (! is_file($file)) {
            return false;
        }

        if (str_replace("\r\n", "\n", trim((string) file_get_contents($file))) === trim(self::LEGACY_ROBOTS_FILE)) {
            return ! @unlink($file);
        }

        return true;
    }
}
