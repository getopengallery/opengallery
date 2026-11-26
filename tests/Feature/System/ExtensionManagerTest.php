<?php

use App\Extensions\ExtensionInstaller;
use App\Extensions\ExtensionInstallFailed;
use App\Extensions\PluginManager;
use App\Extensions\ThemeManager;
use App\Models\User;
use App\Support\AdminTheme;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

describe('themes', function () {
    it('ships a default theme with light and dark tokens', function () {
        $themes = app(ThemeManager::class);

        expect($themes->active()->slug)->toBe('default')
            ->and($themes->cssVariables())
            ->toContain(':root { --og-canvas: 255 255 255;')
            ->toContain(':root.dark { --og-canvas: 27 27 27;');
    });

    it('activates themes and inherits settings from the parent theme', function () {
        $themes = useFixtureThemes();
        expect($themes->active()->slug)->toBe('plain');

        $themes->activate('contact-sheet');

        expect($themes->active()->slug)->toBe('contact-sheet')
            ->and($themes->cssVariables())
            ->toContain('--og-primary: 194 65 12;')      // child override, hex converted
            ->toContain('--og-canvas: 255 255 255;')     // inherited from parent
            ->toContain(':root.dark { --og-primary: 237 237 237; }')
            ->not->toContain('display:none')             // unsafe values are dropped
            ->and(AdminTheme::primary()[600])->toBe(expectedShade('#C2410C'));
    });

    it('lets a theme choose block variants and override block views', function () {
        $themes = useFixtureThemes();
        $themes->activate('contact-sheet');
        $themes->boot();

        expect($themes->blockView('photo-grid'))->toBe('blocks.photo-grid.square')
            ->and($themes->blockView('album-grid'))->toBe('blocks.album-grid'); // unknown variant falls back

        $this->get(route('home'))->assertSee('contact-sheet footer');
    });
});

describe('theme seams', function () {
    it('lets a theme override package views under views/vendor', function () {
        useFixtureThemes('contact-sheet');

        expect(view('pagination::tailwind', ['paginator' => pagedItems(), 'elements' => []])->render())
            ->toContain('contact-sheet vendor pagination');
    });

    it('lets a theme override e-mail components', function () {
        useFixtureThemes('contact-sheet');

        $html = (string) (new ResetPassword('secret-token'))->toMail(User::factory()->create())->render();

        expect($html)->toContain('contact-sheet mail button');
    });

    it('lets a theme change core copy through its language files, child before parent', function () {
        useFixtureThemes('contact-sheet');

        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertSee('Contact sheets')   // contact-sheet/lang/en.json
            ->assertSee('Plain shelf')      // inherited from plain/lang/en.json
            ->assertDontSee('Plain albums');
    });

    it('escapes text passed to a block exactly once', function () {
        $html = Blade::render('<x-block name="empty-state" :message="$message" />', ['message' => 'Tom\'s <b>"camp"</b> & more']);

        expect($html)->toContain('Tom&#039;s &lt;b&gt;&quot;camp&quot;&lt;/b&gt; &amp; more');
    });

    it('renders pagination through the themeable block, with theme tokens', function () {
        $html = pagedItems()->links()->toHtml();

        expect($html)->toContain('border-line', 'bg-canvas')
            ->not->toContain('gray-');
    });

    it('uses a pagination variant shipped by the theme', function () {
        $themes = useFixtureThemes('contact-sheet');

        expect($themes->blockView('pagination'))->toBe('blocks.pagination.compact')
            ->and(pagedItems()->links()->toHtml())->toContain('contact-sheet compact pagination 2/5');
    });

    it('lets a child theme extend, change and drop its parent\'s settings', function () {
        $themes = useFixtureThemes('contact-sheet');
        $sections = $themes->settingSections('contact-sheet');

        expect(array_column($sections, 'label'))->toBe(['Look and feel', 'Contact sheet'])
            ->and(array_column($sections[0]['fields'], 'key'))->toBe(['auth_layout', 'tagline', 'tilt'])
            ->and($sections[0]['fields'][0])->toMatchArray([
                'type' => 'layout',
                'block' => 'auth-shell',
                'default' => 'polaroid',
                'options' => ['default' => 'Centered', 'polaroid' => 'Polaroid'],
            ])
            ->and($themes->options('contact-sheet'))->toMatchArray(['tagline' => 'Plain tagline', 'tilt' => true, 'frame_colour' => 'orange'])
            ->and($themes->settingSections('plain'))->toHaveCount(2);

        // The child's own layout, picked by the merged default and reading the child's own option.
        expect($themes->blockView('auth-shell'))->toBe('blocks.auth-shell.polaroid');
        $this->get(route('login'))->assertOk()->assertSee('polaroid tilted');
    });

    it('keeps raw hex colours out of the core blocks and the default theme', function () {
        $files = collect(File::allFiles(resource_path('views/blocks')))
            ->merge(File::allFiles(base_path('themes/default/views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'));

        $offenders = $files
            ->filter(fn ($file) => preg_match('/-\[#[0-9A-Fa-f]{3,6}\]/', $file->getContents()) === 1)
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()
            ->all();

        expect($files)->not->toBeEmpty()
            ->and($offenders)->toBe([]);
    });
});

describe('plugins', function () {
    it('enables and disables plugins', function () {
        $plugins = app(PluginManager::class);

        expect($plugins->all()->has('example'))->toBeTrue()
            ->and($plugins->isEnabled('example'))->toBeFalse();

        $plugins->enable('example');
        expect($plugins->isEnabled('example'))->toBeTrue();

        $plugins->disable('example');
        expect($plugins->isEnabled('example'))->toBeFalse();
    });
});

describe('installing from a zip', function () {
    beforeEach(function () {
        $this->root = storage_path('framework/testing/extensions-'.uniqid());
        File::ensureDirectoryExists($this->root.'/themes');
        File::ensureDirectoryExists($this->root.'/plugins');
        File::copyDirectory(base_path('themes/default'), $this->root.'/themes/default');

        app()->instance(ThemeManager::class, new ThemeManager($this->root.'/themes', 'default'));
        app()->instance(PluginManager::class, new PluginManager(app(), $this->root.'/plugins'));
    });

    afterEach(fn () => File::deleteDirectory($this->root));

    it('replaces an installed extension only when asked to', function () {
        $installer = app(ExtensionInstaller::class);
        $zip = fn (string $version) => extensionZipPath(['sunset/theme.json' => '{"name": "Sunset", "version": "'.$version.'"}', 'sunset/assets/'.$version.'.css' => '']);

        $installer->installTheme($zip('1.0.0'), 'sunset.zip');

        expect(fn () => $installer->installTheme($zip('2.0.0'), 'sunset.zip'))
            ->toThrow(ExtensionInstallFailed::class, '"sunset" is already installed.');

        $installer->installTheme($zip('2.0.0'), 'sunset.zip', replace: true);

        expect(app(ThemeManager::class)->all()->get('sunset')->version)->toBe('2.0.0')
            ->and(is_file($this->root.'/themes/sunset/assets/1.0.0.css'))->toBeFalse()
            ->and(glob($this->root.'/themes/.*'))->toBe([$this->root.'/themes/.', $this->root.'/themes/..']);
    });

    it('refuses zips that would write outside their folder, contain links or lack a manifest', function (array $files, string $message) {
        expect(fn () => app(ExtensionInstaller::class)->installPlugin(extensionZipPath($files), 'evil.zip'))
            ->toThrow(ExtensionInstallFailed::class, $message);

        expect(File::directories($this->root.'/plugins'))->toBe([])
            ->and(file_exists($this->root.'/evil.php'))->toBeFalse();
    })->with([
        'path traversal' => [['evil/plugin.json' => '{"name": "Evil"}', 'evil/../../evil.php' => '<?php'], 'paths outside its folder'],
        'symbolic link' => [['evil/plugin.json' => '{"name": "Evil"}', 'evil/passwd' => null], 'links'],
        'no manifest' => [['evil/readme.txt' => 'hi'], 'has no plugin.json'],
        'two folders' => [['a/plugin.json' => '{"name": "A"}', 'b/plugin.json' => '{"name": "B"}'], 'has no plugin.json'],
        'manifest without a name' => [['evil/plugin.json' => '{"version": "1.0.0"}'], 'needs at least a "name"'],
        'unusable folder name' => [['bad name!/plugin.json' => '{"name": "Bad"}'], 'cannot be used as a folder name'],
    ]);
});
