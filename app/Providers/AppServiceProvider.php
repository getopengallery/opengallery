<?php

namespace App\Providers;

use App\Enums\Role;
use App\Extensions\DashboardWidgets;
use App\Extensions\LoginOptions;
use App\Extensions\PluginManager;
use App\Extensions\ThemeManager;
use App\Extensions\TranslationLoader;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Models\Group;
use App\Models\Page;
use App\Models\Photo;
use App\Models\Pivots\AlbumCollaborator;
use App\Models\Pivots\AlbumGroup;
use App\Models\Pivots\AlbumGroupCollaborator;
use App\Models\Pivots\AlbumUser;
use App\Models\Pivots\GroupUser;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Observers\ActivityObserver;
use App\Support\AccountApproval;
use App\Support\AccountNotices;
use App\Support\ActivityLog;
use App\Support\AlbumCollaboration;
use App\Support\DateFormat;
use App\Support\Locales;
use App\Support\MailboxTransport;
use App\Support\MailQueue;
use App\Support\PrivateDatabaseSessionHandler;
use App\Support\ProductionCaches;
use App\Support\RequestMemo;
use App\Support\SecurityNotices;
use App\Support\Setup\Installation;
use App\Support\SiteSettings;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Before setup there may be no database: the wizard keeps sessions and cache in files.
        // First, before plugins or other providers reach for the cache.
        if (! $this->app->runningInConsole() && ! Installation::isInstalled()) {
            Installation::prepare($this->app);
        }

        $this->app->singleton(ThemeManager::class, fn () => new ThemeManager(
            config('gallery.themes_path'),
            config('gallery.default_theme'),
        ));

        $this->app->singleton(PluginManager::class, fn ($app) => new PluginManager(
            $app,
            config('gallery.plugins_path'),
        ));

        $this->app->singleton(LoginOptions::class);
        $this->app->singleton(DashboardWidgets::class);

        // Starts empty for every request, queued job and test (see RequestMemo).
        $this->app->scoped(RequestMemo::class);

        // Themes' language folders are override paths, not lookup paths (see TranslationLoader).
        $this->app->extend('translation.loader', fn (FileLoader $loader, $app) => tap(
            new TranslationLoader($app['files'], $loader->paths()),
            function (TranslationLoader $extended) use ($loader) {
                foreach ($loader->jsonPaths() as $path) {
                    $extended->addJsonPath($path);
                }
                foreach ($loader->namespaces() as $namespace => $hint) {
                    $extended->addNamespace($namespace, $hint);
                }
            },
        ));

        // Load enabled plugins now (register phase), so they can contribute
        // to anything configured later, such as the admin panel.
        $this->app->make(PluginManager::class)->register();
    }

    /**
     * Limits for forms that check a password or send e-mail. Named, so each
     * has its own counter: plain "throttle:6,1" limits share one per visitor.
     * "password-check" is one counter per account across every form that asks
     * for the current password.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('password-email', fn (Request $request) => [
            Limit::perMinute(5)->by('minute:'.$request->ip()),
            Limit::perHour(30)->by('hour:'.$request->ip()),
        ]);
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by('minute:'.$request->ip()),
            Limit::perHour(20)->by('hour:'.$request->ip()),
        ]);
        RateLimiter::for('password-check', fn (Request $request) => $request->filled('current_password') || $request->filled('password')
            ? Limit::perMinute(6)->by((string) ($request->user()?->id ?? $request->ip()))
            : Limit::none());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Database sessions without IP address and user agent (never stored, see PrivateDatabaseSessionHandler).
        Session::extend('database', fn (Application $app) => new PrivateDatabaseSessionHandler(
            $app['db']->connection($app['config']->get('session.connection')),
            $app['config']->get('session.table'),
            $app['config']->get('session.lifetime'),
            $app,
        ));

        // One password rule everywhere: sign-up, reset, profile, the user form and the installer.
        Password::defaults(fn () => Password::min(12));

        $this->configureRateLimiting();

        // "Test mailbox" delivery: e-mails are kept in the gallery instead of being sent.
        Mail::extend('mailbox', fn () => new MailboxTransport);

        // Super admins pass every authorization check.
        Gate::before(fn (User $user) => $user->hasRole(Role::SuperAdmin->value) ? true : null);

        // Every new account (local or OAuth) starts as a regular user.
        Event::listen(Registered::class, function (Registered $event) {
            if ($event->user instanceof User && $event->user->roles()->doesntExist()) {
                $event->user->assignRole(Role::User->value);
            }

            if ($event->user instanceof User) {
                AccountApproval::holdIfRequired($event->user);
                AccountNotices::registered($event->user);
            }
        });

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                ActivityLog::login($event->user);
            }
        });

        // Security events. E-mail addresses typed into the login form are never stored.
        Event::listen(Failed::class, function (Failed $event) {
            ActivityLog::loginFailed($event->user instanceof User ? $event->user : null, request());
        });

        Event::listen(Lockout::class, function (Lockout $event) {
            $email = (string) $event->request->input('email');
            $user = $email !== '' ? User::where('email', $email)->first() : null;
            ActivityLog::lockedOut($user, $event->request);
            SecurityNotices::lockedOut($email, $user);
        });

        Event::listen(MessageSent::class, fn () => MailQueue::countSent());

        Event::listen(PasswordReset::class, function (PasswordReset $event) {
            if ($event->user instanceof User) {
                ActivityLog::passwordReset($event->user);
            }
        });

        // Values kept for the rest of a request (RequestMemo) are worked out again as soon as
        // what they depend on changes: shares and group members (Album::idsSharedWith()) and
        // the trash (TrashActions::count()).
        Event::listen(
            collect([AlbumUser::class, AlbumGroup::class, GroupUser::class])
                ->flatMap(fn (string $pivot) => ["eloquent.created: {$pivot}", "eloquent.deleted: {$pivot}"])
                ->push('eloquent.deleted: '.Group::class)
                ->all(),
            fn () => RequestMemo::instance()->forget(Album::SHARES_MEMO),
        );
        // Collaborator roles also follow group members and the album tree. Every album save counts:
        // refreshing the tree path saves again, which clears wasChanged('parent_id').
        Event::listen(
            collect([AlbumCollaborator::class, AlbumGroupCollaborator::class])
                ->flatMap(fn (string $pivot) => ["eloquent.created: {$pivot}", "eloquent.updated: {$pivot}", "eloquent.deleted: {$pivot}"])
                ->push('eloquent.created: '.GroupUser::class, 'eloquent.deleted: '.GroupUser::class, 'eloquent.deleted: '.Group::class)
                ->push('eloquent.saved: '.Album::class, 'eloquent.deleted: '.Album::class, 'eloquent.restored: '.Album::class)
                ->all(),
            fn () => RequestMemo::instance()->forget(AlbumCollaboration::MEMO),
        );
        Event::listen(
            ['eloquent.deleted: *', 'eloquent.restored: *', 'eloquent.forceDeleted: *'],
            fn () => RequestMemo::instance()->forget(TrashActions::COUNT_MEMO),
        );
        // Each HTTP request starts with an empty RequestMemo, also when one process handles several (tests).
        Event::listen(RequestHandled::class, fn () => $this->app->forgetInstance(RequestMemo::class));

        // Bulk actions in the admin are logged as one entry ("Moved 200 photos to the trash").
        BulkAction::configureUsing(fn (BulkAction $action) => $action
            ->before(fn () => ActivityLog::startBatch())
            ->after(fn () => ActivityLog::finishBatch()));

        // Every admin list filters the same way: a filter button next to the search
        // that opens a dropdown and applies changes immediately (no "Apply" step).
        Table::configureUsing(fn (Table $table) => $table
            ->filtersLayout(FiltersLayout::Dropdown)
            ->deferFilters(false));

        // One date format everywhere (App\Support\DateFormat): tables, infolists and date
        // pickers show 20.11.2026 14:30 in every language. Pickers use Filament's own
        // calendar, since the browser's native one follows the browser's locale instead.
        foreach ([Table::class, Schema::class] as $container) {
            $container::configureUsing(fn ($container) => $container
                ->defaultDateDisplayFormat(DateFormat::DATE)
                ->defaultDateTimeDisplayFormat(DateFormat::DATE_TIME)
                ->defaultTimeDisplayFormat(DateFormat::TIME)
                ->defaultIsoDateDisplayFormat(DateFormat::ISO_DATE)
                ->defaultIsoDateTimeDisplayFormat(DateFormat::ISO_DATE_TIME)
                ->defaultIsoTimeDisplayFormat(DateFormat::ISO_TIME));
        }
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->native(false)
            ->firstDayOfWeek(1)
            ->defaultDateDisplayFormat(DateFormat::DATE)
            ->defaultDateTimeDisplayFormat(DateFormat::DATE_TIME)
            ->defaultDateTimeWithSecondsDisplayFormat(DateFormat::DATE_TIME_SECONDS)
            ->defaultTimeDisplayFormat(DateFormat::TIME));

        // Labels Filament derives from a field or column name ("Created at") go through __()
        // too, so they are translated and TranslationFilesTest catches missing ones.
        // Explicit labels are already translated; translating them again is harmless.
        foreach ([Field::class, Column::class, BaseFilter::class, Entry::class] as $component) {
            $component::configureUsing(fn ($component) => $component->translateLabel());
        }

        // Icon-only buttons (row actions such as Edit or Open on the site) show their label as a
        // tooltip. An explicit ->tooltip() on the action still wins.
        Action::configureUsing(fn (Action $action) => $action->tooltip(
            fn (Action $action): string|Htmlable|null => $action->isIconButton() ? $action->getLabel() : null,
        ));

        // "Delete" always means for good: things that can come back are moved to the trash
        // instead (App\Filament\Trash\TrashActions, which words its own confirmations).
        foreach ([DeleteAction::class, DeleteBulkAction::class] as $action) {
            $action::configureUsing(fn ($action) => $action->modalDescription(fn (): string => __('This cannot be undone.')));
        }

        foreach ([Album::class, User::class, Group::class, RoleModel::class, Page::class, Photo::class] as $model) {
            $model::observe(ActivityObserver::class);
        }

        // Stored settings and the theme only change the configuration at runtime: the
        // configuration cache holds what the files say (no decrypted secrets, no theme paths).
        if (ProductionCaches::buildingConfigCache()) {
            return;
        }

        SiteSettings::apply();

        // The site default until SetLocale picks the user's own language (also for console and queue).
        $this->app->setLocale(Locales::default());
        Number::useLocale(Locales::default());

        $this->app->make(ThemeManager::class)->boot();
    }
}
