<?php

namespace App\Http\Controllers;

use App\Support\SearchEngines;
use App\Support\SiteSettings;
use Illuminate\Http\Response;

/** robots.txt and sitemap.xml (App\Support\SearchEngines). */
class SearchEngineController extends Controller
{
    public function robots(): Response
    {
        return response(SearchEngines::robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Not there while search engines are kept out (Admin → Settings → Search engines). */
    public function sitemap(): Response
    {
        abort_unless(SiteSettings::searchIndexing(), 404);

        return response()
            ->view('search.sitemap', ['entries' => SearchEngines::sitemapEntries()])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
