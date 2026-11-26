<?php

use App\Extensions\ThemeManager;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/** Activates the "contact-sheet" fixture theme (child of "plain") and wires it up. */
function bootContactSheetTheme(): ThemeManager
{
    $themes = new ThemeManager(base_path('tests/Fixtures/themes'), 'plain');
    app()->instance(ThemeManager::class, $themes);
    $themes->activate('contact-sheet');
    $themes->boot();

    return $themes;
}

function pagedItems(): LengthAwarePaginator
{
    return new LengthAwarePaginator(range(1, 10), 50, 10, 2, ['path' => '/albums']);
}

it('renders error pages from the theme', function () {
    bootContactSheetTheme();

    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('contact-sheet not found');
});

it('lets a theme override package views under views/vendor', function () {
    bootContactSheetTheme();

    expect(view('pagination::tailwind', ['paginator' => pagedItems(), 'elements' => []])->render())
        ->toContain('contact-sheet vendor pagination');
});

it('lets a theme override e-mail components', function () {
    bootContactSheetTheme();

    $html = (string) (new ResetPassword('secret-token'))->toMail(User::factory()->create())->render();

    expect($html)->toContain('contact-sheet mail button');
});

it('lets a theme change core copy through its language files, child before parent', function () {
    bootContactSheetTheme();

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
    $themes = bootContactSheetTheme();

    expect($themes->blockView('pagination'))->toBe('blocks.pagination.compact')
        ->and(pagedItems()->links()->toHtml())->toContain('contact-sheet compact pagination 2/5');
});

it('lets a child theme extend, change and drop its parent\'s settings', function () {
    $themes = bootContactSheetTheme();
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
