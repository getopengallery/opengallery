<?php

namespace Plugins\Example;

use App\Extensions\DashboardWidgets;
use App\Models\Photo;
use Filament\Panel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Plugins\Example\Filament\ExamplePage;
use Plugins\Example\Filament\ExampleWidget;

/**
 * A plugin is a plain Laravel service provider, loaded only while enabled
 * (Admin → Plugins). Typical hooks:
 *
 *  - Event::listen(...)           react to core events (Registered, model events, ...)
 *  - Photo::created(...)          e.g. watermarking, AI tagging
 *  - $this->loadRoutesFrom(...)   add pages
 *  - $this->loadViewsFrom(..., 'example')   ship views under example::
 *  - Panel::configureUsing(...)   add Filament resources / pages to the admin
 *  - DashboardWidgets             add widgets to the admin dashboard
 *  - LoginOptions / PluginSettings   add sign-in buttons or a settings page
 *    (see the identity-providers plugin)
 */
class ExampleServiceProvider extends ServiceProvider
{
    /**
     * Admin screens: Filament runs this when the admin panel is set up, which
     * happens after plugins are registered. Must not touch the database here.
     */
    public function register(): void
    {
        Panel::configureUsing(fn (Panel $panel) => $panel->pages([ExamplePage::class]));

        // A dashboard widget under a stable key (prefixed with the plugin slug).
        $this->app->make(DashboardWidgets::class)->register('example.hello', ExampleWidget::class, sort: 200);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'example');

        Event::listen(RequestHandled::class, function (RequestHandled $event) {
            $event->response->headers->set('X-Gallery-Plugin', 'example');
        });

        Photo::created(function (Photo $photo) {
            logger()->debug("[example plugin] photo {$photo->id} uploaded");
        });
    }
}
