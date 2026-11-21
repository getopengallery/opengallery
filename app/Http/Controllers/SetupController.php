<?php

namespace App\Http\Controllers;

use App\Enums\BackgroundJobMode;
use App\Enums\MailDelivery;
use App\Enums\Role;
use App\Enums\SetupStep;
use App\Enums\UpdateChannel;
use App\Models\Setting;
use App\Models\User;
use App\Support\Locales;
use App\Support\Setup\DatabaseSetup;
use App\Support\Setup\Installation;
use App\Support\Setup\Requirements;
use App\Support\SiteSettings;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * The web setup wizard at `/setup`, for installations without a console:
 * requirements and language, database (written to `.env`), the first super
 * administrator, the main site settings and optional demo content.
 *
 * Open only until the installation is finished (EnsureSetupIsOpen). Once a
 * super administrator exists, every step needs the session that created it
 * or that administrator's password (the "unlock" page), so nobody else can
 * change the database or add demo accounts in the meantime.
 */
class SetupController extends Controller
{
    /** Demo content in the wizard is a smaller version (about 30 photos), so it fits in one request. */
    public const DEMO_SCALE = 0.1;

    public function welcome(Request $request): View
    {
        return $this->step($request, SetupStep::Welcome, [
            'checks' => Requirements::checks(),
            'passed' => Requirements::passed(),
            'locales' => Locales::available(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(Locales::available()))]]);

        $request->session()->put('setup.locale', $data['locale']);

        if (! Requirements::passed()) {
            return back()->withErrors(['requirements' => __('This server does not meet all requirements yet.')]);
        }

        $request->session()->put('setup.started', true);

        return redirect()->route($this->nextStep($request)->route());
    }

    public function database(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, SetupStep::Database)) {
            return $redirect;
        }

        $current = (string) config('database.default');
        $drivers = Requirements::databaseDrivers();

        return $this->step($request, SetupStep::Database, [
            'drivers' => $drivers,
            'ready' => Installation::databaseIsReady(),
            'values' => [
                'connection' => in_array($current, $drivers, true) ? $current : ($drivers[0] ?? 'mysql'),
                'host' => config("database.connections.{$current}.host", '127.0.0.1'),
                'port' => config("database.connections.{$current}.port", '3306'),
                'database' => $current === 'sqlite' ? '' : config("database.connections.{$current}.database"),
                'username' => config("database.connections.{$current}.username"),
            ],
        ]);
    }

    public function saveDatabase(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, SetupStep::Database)) {
            return $redirect;
        }

        $server = fn () => $request->input('connection') !== 'sqlite';
        $input = $request->validate([
            'connection' => ['required', Rule::in(Requirements::databaseDrivers())],
            'host' => [Rule::requiredIf($server), 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'port' => [Rule::requiredIf($server), 'nullable', 'integer', 'between:1,65535'],
            'database' => [Rule::requiredIf($server), 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'username' => [Rule::requiredIf($server), 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'password' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ]);

        if (($error = DatabaseSetup::check($input)) !== null) {
            return back()->withInput($request->except('password'))
                ->withErrors(['connection' => __('Could not connect to the database: :error', ['error' => $error])]);
        }

        @set_time_limit(300);

        try {
            DatabaseSetup::apply($input, rtrim($request->root(), '/'));
        } catch (Throwable $exception) {
            return back()->withInput($request->except('password'))
                ->withErrors(['connection' => __('The database could not be set up: :error', ['error' => $exception->getMessage()])]);
        }

        return redirect()->route($this->nextStep($request)->route());
    }

    public function administrator(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, SetupStep::Administrator)) {
            return $redirect;
        }

        return $this->step($request, SetupStep::Administrator, [
            'existing' => Installation::hasSuperAdmin() ? User::find($request->session()->get('setup.admin')) : null,
        ]);
    }

    public function saveAdministrator(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, SetupStep::Administrator)) {
            return $redirect;
        }

        if (Installation::hasSuperAdmin()) {
            return redirect()->route($this->nextStep($request)->route());
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = Installation::createSuperAdmin($data);
        $user->forceFill(['locale' => $request->session()->get('setup.locale')])->save();

        $request->session()->regenerate();
        $request->session()->put('setup.admin', $user->id);

        return redirect()->route($this->nextStep($request)->route());
    }

    /** Asks for a super administrator's password before the wizard continues in another session. */
    public function unlock(Request $request): View|RedirectResponse
    {
        if (! $this->needsUnlock($request)) {
            return redirect()->route($this->nextStep($request)->route());
        }

        return $this->step($request, SetupStep::Administrator, [], 'setup.unlock');
    }

    public function confirmUnlock(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->hasRole(Role::SuperAdmin->value)) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('These details do not match a super administrator.')]);
        }

        $request->session()->regenerate();
        $request->session()->put('setup.admin', $user->id);

        return redirect()->route($this->nextStep($request)->route());
    }

    public function site(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, SetupStep::Site)) {
            return $redirect;
        }

        $mail = SiteSettings::storedMail();

        return $this->step($request, SetupStep::Site, [
            'locales' => Locales::available(),
            'deliveries' => MailDelivery::cases(),
            'jobModes' => BackgroundJobMode::cases(),
            'updateChannels' => UpdateChannel::cases(),
            'hasMailPassword' => SiteSettings::hasMailPassword(),
            'values' => [
                'name' => SiteSettings::name(),
                'locale' => Setting::get(SiteSettings::LOCALE) ?? $request->session()->get('setup.locale', Locales::default()),
                'public_albums' => SiteSettings::publicAlbumsEnabled(),
                'registration' => SiteSettings::registrationEnabled(),
                'require_approval' => SiteSettings::requiresApproval(),
                'mail_delivery' => SiteSettings::mailDelivery()->value,
                'mail_host' => $mail['host'],
                'mail_port' => $mail['port'] ?? 587,
                'mail_scheme' => $mail['scheme'] ?? 'smtp',
                'mail_username' => $mail['username'],
                'mail_from_address' => $mail['from_address'],
                'background_jobs' => SiteSettings::backgroundJobs()->value,
                'update_checks' => (bool) Setting::get(SiteSettings::UPDATE_CHECKS, true),
                'update_channel' => SiteSettings::updateChannel()->value,
            ],
        ]);
    }

    public function saveSite(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, SetupStep::Site)) {
            return $redirect;
        }

        $smtp = fn () => $request->input('mail_delivery') === MailDelivery::Smtp->value;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', Rule::in(array_keys(Locales::available()))],
            'public_albums' => ['boolean'],
            'registration' => ['boolean'],
            'require_approval' => ['boolean'],
            'mail_delivery' => ['required', new Enum(MailDelivery::class)],
            'mail_host' => [Rule::requiredIf($smtp), 'nullable', 'string', 'max:255'],
            'mail_port' => [Rule::requiredIf($smtp), 'nullable', 'integer', 'between:1,65535'],
            'mail_scheme' => ['nullable', Rule::in(['smtp', 'smtps'])],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'background_jobs' => ['required', new Enum(BackgroundJobMode::class)],
            'update_checks' => ['boolean'],
            'update_channel' => ['nullable', new Enum(UpdateChannel::class)],
        ]);

        Setting::put(SiteSettings::NAME, trim($data['name']));
        Setting::put(SiteSettings::LOCALE, $data['locale']);
        Setting::put(SiteSettings::PUBLIC_ALBUMS, $request->boolean('public_albums'));
        Setting::put(SiteSettings::REGISTRATION, $request->boolean('registration'));
        Setting::put(SiteSettings::REQUIRE_APPROVAL, $request->boolean('require_approval'));
        Setting::put(SiteSettings::BACKGROUND_JOBS, $data['background_jobs']);
        Setting::put(SiteSettings::UPDATE_CHECKS, $request->boolean('update_checks'));
        Setting::put(SiteSettings::UPDATE_CHANNEL, $data['update_channel'] ?? UpdateChannel::Stable->value);
        Setting::put(SiteSettings::MAIL_DELIVERY, $data['mail_delivery']);

        if ($smtp()) {
            Setting::put(SiteSettings::MAIL_HOST, trim((string) $data['mail_host']));
            Setting::put(SiteSettings::MAIL_PORT, (int) $data['mail_port']);
            Setting::put(SiteSettings::MAIL_SCHEME, $data['mail_scheme'] ?? 'smtp');
            Setting::put(SiteSettings::MAIL_USERNAME, filled($data['mail_username'] ?? null) ? trim($data['mail_username']) : null);
            Setting::put(SiteSettings::MAIL_FROM_ADDRESS, filled($data['mail_from_address'] ?? null) ? trim($data['mail_from_address']) : null);

            // An empty password field keeps a saved one; without a user name there is nothing to log in with.
            if (blank($data['mail_username'] ?? null)) {
                Setting::put(SiteSettings::MAIL_PASSWORD, null);
            } elseif (filled($data['mail_password'] ?? null)) {
                Setting::put(SiteSettings::MAIL_PASSWORD, SiteSettings::encrypt($data['mail_password']));
            }
        }

        SiteSettings::apply();
        $request->session()->put('setup.site', true);

        return redirect()->route(SetupStep::Demo->route());
    }

    public function demo(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, SetupStep::Demo)) {
            return $redirect;
        }

        return $this->step($request, SetupStep::Demo);
    }

    /** Adds the demo content if chosen and finishes the installation. */
    public function finish(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, SetupStep::Demo)) {
            return $redirect;
        }

        $data = $request->validate(['content' => ['required', Rule::in(['empty', 'demo'])]]);
        $withDemo = $data['content'] === 'demo';
        $admin = User::findOrFail($request->session()->get('setup.admin'));

        if ($withDemo) {
            @set_time_limit(0);
            ignore_user_abort(true);

            try {
                $this->seedDemo($admin);
            } catch (Throwable $exception) {
                return back()->withErrors(['content' => __('The demo content could not be added: :error', ['error' => $exception->getMessage()])]);
            }
        }

        Installation::markInstalled();

        return view('setup.done', [
            'admin' => $admin,
            'withDemo' => $withDemo,
            'demoPassword' => DemoSeeder::PASSWORD,
            'albumPassword' => DemoSeeder::ALBUM_PASSWORD,
            'jobMode' => SiteSettings::backgroundJobs(),
            'mailDelivery' => SiteSettings::mailDelivery(),
        ]);
    }

    /** @throws RuntimeException when the seeder fails */
    private function seedDemo(User $admin): void
    {
        $scale = DemoSeeder::$photoScale;
        DemoSeeder::$photoScale = min($scale, self::DEMO_SCALE);
        DemoSeeder::$administrator = $admin;

        try {
            if (Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]) !== 0) {
                throw new RuntimeException(trim(Artisan::output()));
            }
        } finally {
            DemoSeeder::$photoScale = $scale;
            DemoSeeder::$administrator = null;
        }
    }

    /**
     * Sends people to the unlock page when a super administrator exists but
     * this session did not prove to be one, and to the first unfinished step
     * when they skip ahead.
     */
    private function guard(Request $request, SetupStep $step): ?RedirectResponse
    {
        if ($this->needsUnlock($request)) {
            return redirect()->route('setup.unlock');
        }

        $next = $this->nextStep($request);

        if ($step->position() > $next->position()) {
            return redirect()->route($next->route());
        }

        return null;
    }

    private function nextStep(Request $request): SetupStep
    {
        return match (true) {
            ! $request->session()->get('setup.started') || ! Requirements::passed() => SetupStep::Welcome,
            ! Installation::databaseIsReady() => SetupStep::Database,
            ! Installation::hasSuperAdmin() => SetupStep::Administrator,
            ! $request->session()->get('setup.site') => SetupStep::Site,
            default => SetupStep::Demo,
        };
    }

    private function needsUnlock(Request $request): bool
    {
        if (! Installation::databaseIsReady() || ! Installation::hasSuperAdmin()) {
            return false;
        }

        $id = $request->session()->get('setup.admin');

        return ! ($id && User::find($id)?->hasRole(Role::SuperAdmin->value));
    }

    /** @param  array<string, mixed>  $data */
    private function step(Request $request, SetupStep $step, array $data = [], ?string $view = null): View
    {
        return view($view ?? 'setup.'.$step->value, [
            ...$data,
            'step' => $step,
            'reached' => $this->nextStep($request),
        ]);
    }
}
