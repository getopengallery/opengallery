<?php

namespace App\Providers\Filament;

use App\Enums\Permission;
use App\Extensions\DashboardWidgets;
use App\Extensions\PluginManager;
use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\GlobalSearch\AdminSearchProvider;
use App\Filament\Navigation\NavigationGroup;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetLocale;
use App\Livewire\DatabaseNotifications;
use App\Models\User;
use App\Support\AdminTheme;
use App\Support\SiteSettings;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /** The admin search covers pages, the library and the docs, so collaborators with only the Albums section do not get it. */
    public function boot(): void
    {
        Filament::serving(function (): void {
            $user = auth()->user();

            Filament::getCurrentOrDefaultPanel()->globalSearch(
                $user instanceof User && ! $user->can(Permission::AccessAdmin->value) ? false : AdminSearchProvider::class,
            );
        });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // No separate admin login: unauthenticated visitors are sent to the
            // site's login page (which also offers OAuth).
            ->brandName(fn () => config('app.name'))
            ->brandLogo(fn () => view('filament.partials.brand', ['dark' => false]))
            ->darkModeBrandLogo(fn () => view('filament.partials.brand', ['dark' => true]))
            ->brandLogoHeight('1.75rem')
            ->favicon(fn () => SiteSettings::faviconUrl())
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->font('Hanken Grotesk Variable', url: fn () => Vite::asset('resources/css/fonts.css'), provider: LocalFontProvider::class)
            ->colors(fn () => [
                'primary' => AdminTheme::primary(),
                'gray' => AdminTheme::gray(),
            ])
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.partials.theme-styles'))
            // Installable app: manifest, browser bar colour, service worker (App\Support\WebApp).
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.web-app'))
            // Chunked photo uploader of the media picker; must be registered before Alpine starts.
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn () => view('filament.partials.photo-uploader-script'))
            // The ImageUpload field (logos, icons, backgrounds, profile pictures); also before Alpine starts.
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn () => view('filament.partials.image-upload-script'))
            // One search for admin pages, records and the documentation.
            ->globalSearch(AdminSearchProvider::class)
            ->globalSearchKeyBindings(['mod+k'])
            // Light/dark switch and account menu built like the site header
            // (filament.partials.topbar-account) instead of Filament's user menu.
            ->userMenu(false)
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn () => view('filament.partials.topbar-account'))
            // The bell (App\Livewire\DatabaseNotifications) is placed by that partial, before the
            // account menu, so Filament's own placement stays off; registering it here keeps
            // Filament's component from taking its Livewire name. It checks for new
            // notifications every minute while a page is open; no websockets needed.
            ->databaseNotificationsLivewireComponent(DatabaseNotifications::class)
            ->databaseNotificationsPollingInterval('60s')
            // Makes search results clickable in Safari (see the partial).
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn () => view('filament.partials.global-search-fix'))
            // Complete rows in every photo grid (see the partial).
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn () => view('filament.partials.media-grid-script'))
            // Background jobs while Settings → Background jobs is "AJAX".
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn () => view('partials.background-jobs'))
            // The installed version, for people who manage the site.
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn () => view('filament.partials.app-version'))
            ->navigationGroups(NavigationGroup::panelGroups())
            ->navigationItems([
                NavigationItem::make('view-site')
                    ->label(fn (): string => __('View site'))
                    ->url(fn () => route('home'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->sort(99),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            // The dashboard (App\Filament\Pages\Dashboard) is discovered with the other pages.
            ->pages(app(PluginManager::class)->settingsPages())
            // Core and plugin dashboard widgets, so Livewire knows them (App\Extensions\DashboardWidgets).
            ->widgets(app(DashboardWidgets::class)->classes())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SetLocale::class,
                EnsureAccountIsActive::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
