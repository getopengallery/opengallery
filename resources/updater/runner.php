<?php

use App\Support\Updates\DatabaseBackup;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * The one-click updater's second half. UpdateInstaller copies this file to
 * `storage/app/updates/runner.php` and starts it from Laravel's maintenance
 * file, which `public/index.php` loads before any application code. So it keeps
 * working while the application is replaced, and even if the new version
 * cannot start.
 *
 * It must stay free of dependencies. Visitors get a "back in a moment" page.
 * The administrator's browser drives one step per request:
 *
 *   backup → swap → migrate → verify → done
 *
 * Any failure after the swap leads to rollback → restore → aborted, which puts
 * the old code and database back. If the browser goes away, the next visitor
 * after STALE_SECONDS starts that recovery, so a site cannot stay offline.
 */
final class OpenGalleryUpdater
{
    private const STALE_SECONDS = 1200;

    private const STEP_SECONDS = 8.0;

    private const COOKIE = 'og_update';

    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** @var array<string, mixed> */
    private array $state = [];

    private bool $working = false;

    /** @var resource|null */
    private $lock = null;

    private ?string $cleanup = null;

    private float $stepSeconds = self::STEP_SECONDS;

    private function __construct(private readonly string $directory) {}

    /** Entry point from the maintenance file. Returns only when no update is running. */
    public static function handle(string $directory): void
    {
        $updater = new self($directory);

        if (! $updater->load()) {
            @unlink(dirname($directory, 2).'/framework/maintenance.php');

            return;
        }

        $updater->route();

        exit;
    }

    /** For tests: an updater working on the state in $directory. */
    public static function forDirectory(string $directory): self
    {
        $updater = new self($directory);
        $updater->load();

        return $updater;
    }

    /** @return array<string, mixed> */
    public function currentState(): array
    {
        return $this->state;
    }

    private function load(): bool
    {
        $file = "{$this->directory}/state.json";
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($state) || ! isset($state['token_hash'], $state['plan'], $state['phase'])) {
            return false;
        }

        $this->state = $state;

        return true;
    }

    private function save(): void
    {
        $this->state['updated_at'] = time();
        $file = "{$this->directory}/state.json";

        if (@file_put_contents("{$file}.tmp", json_encode($this->state, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! @rename("{$file}.tmp", $file)) {
            throw new RuntimeException('The update state could not be saved.');
        }
    }

    private function route(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $posted = $_POST['og_update_token'] ?? null;

        if ($method === 'POST' && is_string($posted) && $this->isValidToken($posted)) {
            setcookie(self::COOKIE, $posted, [
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => ($_SERVER['HTTPS'] ?? 'off') !== 'off',
            ]);
            header('Cache-Control: no-store');
            header('Location: '.$this->state['home_url'], true, 303);

            return;
        }

        $cookie = $_COOKIE[self::COOKIE] ?? null;

        if (is_string($cookie) && $this->isValidToken($cookie)) {
            $action = $_SERVER['HTTP_X_OG_UPDATE'] ?? null;

            if ($method === 'POST' && in_array($action, ['step', 'abort', 'retry'], true)) {
                $this->respond($action);

                return;
            }

            $this->renderProgress();

            return;
        }

        if (time() - (int) ($this->state['updated_at'] ?? 0) > self::STALE_SECONDS) {
            $this->recover();
        }

        $this->renderUnavailable();
    }

    private function isValidToken(string $token): bool
    {
        return hash_equals((string) $this->state['token_hash'], hash('sha256', $token));
    }

    private function respond(string $action): void
    {
        ignore_user_abort(true);
        @set_time_limit(180);

        if (! $this->acquireLock()) {
            $this->json(['busy' => true, 'phase' => $this->state['phase']]);

            return;
        }

        ob_start();
        register_shutdown_function([$this, 'onShutdown']);
        $this->working = true;

        if ($action === 'abort') {
            $this->fail('The browser could not complete this step.');
        } elseif ($action === 'retry' && $this->state['phase'] === 'stuck') {
            $this->state['phase'] = $this->state['stuck_phase'];
            $this->save();
        } else {
            $this->step();
        }

        $this->working = false;

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->json($this->summary());
        $this->removeLeftovers();
    }

    /** One step of the update; every step can safely run again after an interruption. */
    public function step(): void
    {
        try {
            match ($this->state['phase']) {
                'backup' => $this->advance($this->backup() ? 'swap' : 'backup'),
                'swap' => $this->advance($this->swap() ? 'migrate' : 'swap'),
                'migrate' => $this->advance($this->migrate() ? 'verify' : 'migrate'),
                'verify' => $this->advance($this->verify() ? 'done' : 'verify'),
                'done' => $this->finish(true),
                'rollback' => $this->advance($this->rollback() ? ($this->state['migrated'] ? 'restore' : 'aborted') : 'rollback'),
                'restore' => $this->advance($this->restore() ? 'aborted' : 'restore'),
                'aborted' => $this->finish(false),
                default => null,
            };
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
    }

    private function advance(string $phase): void
    {
        $this->state['phase'] = $phase;
        $this->save();
    }

    /** Sends the update down the way back. A failure on the way back stops and keeps the site offline. */
    public function fail(string $reason): void
    {
        $this->state['error'] ??= mb_substr($reason, 0, 500);
        $phase = (string) $this->state['phase'];

        if ($phase === 'backup') {
            $this->state['phase'] = 'aborted';
        } elseif (in_array($phase, ['swap', 'migrate', 'verify', 'done'], true)) {
            $this->state['phase'] = 'rollback';
        } elseif (in_array($phase, ['rollback', 'restore', 'aborted'], true)) {
            $this->state['stuck_phase'] = $phase;
            $this->state['phase'] = 'stuck';
        }

        $this->save();
    }

    /** A fatal error in the new code ends the request; this still sends the update on its way back. */
    public function onShutdown(): void
    {
        if (! $this->working) {
            return;
        }

        $error = error_get_last();
        $this->working = false;

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        try {
            $this->fail(is_array($error) && ($error['type'] & self::FATAL) ? $error['message'] : 'The step ended unexpectedly.');
        } catch (Throwable) {
            // The state file could not be written; the next request sees the old phase.
        }

        if (! headers_sent()) {
            http_response_code(200);
        }

        $this->json($this->summary());
    }

    /** Puts a site back whose administrator went away during the update. */
    private function recover(): void
    {
        if (! $this->acquireLock()) {
            return;
        }

        ignore_user_abort(true);
        @set_time_limit(0);
        $this->stepSeconds = 120.0;
        ob_start();
        register_shutdown_function([$this, 'onShutdown']);
        $this->working = true;

        if (in_array($this->state['phase'], ['backup', 'swap', 'migrate', 'verify', 'done'], true)) {
            $this->fail('The update was interrupted.');
        }

        for ($steps = 0; $steps < 10000 && in_array($this->state['phase'], ['rollback', 'restore', 'aborted'], true); $steps++) {
            $this->step();
        }

        $this->working = false;

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->removeLeftovers();
    }

    private function acquireLock(): bool
    {
        $lock = @fopen("{$this->directory}/lock", 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }

        $this->lock = $lock;

        // Another request may have moved on while this one waited.
        return $this->load();
    }

    private function backup(): bool
    {
        $this->bootConsole();

        return (new DatabaseBackup("{$this->directory}/database"))
            ->backup(microtime(true) + $this->stepSeconds);
    }

    /** Every rename is recorded first, so an interrupted swap can be undone exactly. */
    public function swap(): bool
    {
        $plan = $this->state['plan'];

        // The step that was interrupted runs again; its checks make that harmless.
        for ($index = max(0, (int) $this->state['swapped'] - 1); $index < count($plan); $index++) {
            $item = $plan[$index];
            $this->state['swapped'] = $index + 1;
            $this->save();

            if ($item['existed'] && $this->exists($item['target']) && ! $this->exists($item['previous'])) {
                $this->ensureParent($item['previous']);
                $this->move($item['target'], $item['previous']);
            }

            if ($this->exists($item['source'])) {
                $this->ensureParent($item['target']);
                $this->move($item['source'], $item['target']);
            }
        }

        $this->resetCaches();

        return true;
    }

    private function migrate(): bool
    {
        $this->state['migrated'] = true;
        $this->save();
        $this->bootConsole();

        $status = Artisan::call('gallery:install', ['--no-interaction' => true]);

        if ($status !== 0) {
            throw new RuntimeException('The database update failed.');
        }

        return true;
    }

    /** The new version must report the right number and answer a real page request. */
    private function verify(): bool
    {
        $base = $this->state['base'];

        if (trim((string) @file_get_contents("{$base}/VERSION")) !== $this->state['version']) {
            throw new RuntimeException('The new version was not installed correctly.');
        }

        require_once "{$base}/vendor/autoload.php";
        $app = require "{$base}/bootstrap/app.php";
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle(Request::create($this->state['check_url']));

        if ($response->getStatusCode() >= 500) {
            throw new RuntimeException('The new version answered with an error.');
        }

        return true;
    }

    /** Puts back whatever the swap moved, newest first; safe to run again. */
    public function rollback(): bool
    {
        $plan = $this->state['plan'];

        for ($index = min((int) $this->state['swapped'], count($plan)) - 1; $index >= 0; $index--) {
            $item = $plan[$index];

            if ($this->exists($item['previous'])) {
                if ($this->exists($item['target'])) {
                    $this->ensureParent($item['source']);
                    $this->move($item['target'], $item['source']);
                }

                $this->move($item['previous'], $item['target']);
            } elseif (! $item['existed'] && $this->exists($item['target']) && ! $this->exists($item['source'])) {
                $this->ensureParent($item['source']);
                $this->move($item['target'], $item['source']);
            }

            $this->state['swapped'] = $index;
            $this->save();
        }

        $this->resetCaches();

        return true;
    }

    private function restore(): bool
    {
        $this->bootConsole();

        return (new DatabaseBackup("{$this->directory}/database"))->restore(
            microtime(true) + $this->stepSeconds,
            ["{$this->state['staging']}/database/migrations"],
        );
    }

    /** Brings the site back online and records how the update ended. */
    public function finish(bool $updated): void
    {
        $entry = (string) $this->state['entry_file'];

        file_put_contents(dirname($this->directory).'/update-result.json', json_encode([
            'status' => $updated ? 'updated' : 'restored',
            'version' => $this->state['version'],
            'from' => $this->state['from'],
            'at' => time(),
            'detail' => $updated ? null : ($this->state['error'] ?? null),
        ], JSON_THROW_ON_ERROR));

        $this->state['phase'] = $updated ? 'finished' : 'restored';
        $this->resetCaches();

        // The site is open again from here on; the work folder is removed after the answer is sent.
        @unlink($entry);
        $old = $this->directory.'-old-'.bin2hex(random_bytes(4));
        $this->cleanup = @rename($this->directory, $old) ? $old : $this->directory;
    }

    private function removeLeftovers(): void
    {
        if ($this->cleanup !== null) {
            if (is_resource($this->lock)) {
                flock($this->lock, LOCK_UN);
                fclose($this->lock);
                $this->lock = null;
            }

            $this->removeTree($this->cleanup);
            $this->cleanup = null;
        }
    }

    private function bootConsole(): void
    {
        $base = $this->state['base'];

        require_once "{$base}/vendor/autoload.php";
        $app = require "{$base}/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    }

    private function resetCaches(): void
    {
        foreach (glob($this->state['base'].'/bootstrap/cache/*.php') ?: [] as $file) {
            @unlink($file);
        }

        clearstatcache(true);

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function ensureParent(string $path): void
    {
        $parent = dirname($path);

        if (! is_dir($parent) && ! @mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new RuntimeException("Could not create {$parent}.");
        }
    }

    private function move(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new RuntimeException("Could not move {$from} to {$to}.");
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree("{$path}/{$entry}");
            }
        }

        @rmdir($path);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $phase = (string) $this->state['phase'];
        $texts = $this->state['texts'] ?? [];

        return [
            'phase' => $phase,
            'label' => $texts[$phase] ?? '',
            'finished' => in_array($phase, ['finished', 'restored'], true),
            'stuck' => $phase === 'stuck',
            'url' => $this->state['return_url'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data): void
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        if (! headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            header('Content-Length: '.strlen($body));
            header('Connection: close');
        }

        echo $body;
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    private function renderUnavailable(): void
    {
        $texts = $this->state['texts'] ?? [];
        $nonce = base64_encode(random_bytes(16));

        http_response_code(503);
        header('Retry-After: 120');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; base-uri 'none'; frame-ancestors 'none'");
        header('Content-Type: text/html; charset=utf-8');

        echo $this->document($texts['unavailable_title'] ?? 'Back in a moment', $nonce,
            '<h1>'.$this->e($texts['unavailable_title'] ?? 'Back in a moment').'</h1><p>'.$this->e($texts['unavailable'] ?? 'This gallery is being updated.').'</p>');
    }

    private function renderProgress(): void
    {
        $texts = $this->state['texts'] ?? [];
        $nonce = base64_encode(random_bytes(16));
        $config = json_encode([
            'labels' => $texts,
            'phase' => $this->state['phase'],
            'url' => $this->state['return_url'],
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        header('Content-Type: text/html; charset=utf-8');

        $body = '<h1>'.$this->e($texts['heading'] ?? '').'</h1>'
            .'<p id="status" role="status"></p><div class="bar"><span id="bar"></span></div>'
            .'<p class="note" id="note">'.$this->e($texts['keep_open'] ?? '').'</p>'
            .'<button id="retry" type="button" hidden>'.$this->e($texts['retry'] ?? 'Try again').'</button>'
            ."<script nonce=\"{$nonce}\">".$this->progressScript($config).'</script>';

        echo $this->document($texts['title'] ?? 'Updating', $nonce, $body);
    }

    private function progressScript(string $config): string
    {
        return <<<JS
        (() => {
            const config = {$config};
            const order = ['backup', 'swap', 'migrate', 'verify', 'done'];
            const status = document.getElementById('status');
            const bar = document.getElementById('bar');
            const note = document.getElementById('note');
            const retry = document.getElementById('retry');
            const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
            const show = (phase, label) => {
                status.textContent = label || config.labels[phase] || '';
                const position = order.indexOf(phase);
                bar.style.width = (position < 0 ? 100 : Math.round(((position + 1) / order.length) * 100)) + '%';
            };
            const send = async (action) => {
                const response = await fetch(window.location.href, { method: 'POST', credentials: 'same-origin', headers: { 'X-OG-Update': action } });
                return response.json();
            };
            const run = async (first) => {
                let action = first;
                let failures = 0;
                for (;;) {
                    let result;
                    try {
                        result = await send(action);
                        failures = 0;
                        action = 'step';
                    } catch (error) {
                        failures += 1;
                        action = failures >= 3 ? 'abort' : 'step';
                        if (failures >= 6) {
                            window.location.reload();
                            return;
                        }
                        await wait(2000);
                        continue;
                    }
                    if (result.busy) {
                        await wait(1500);
                        continue;
                    }
                    show(result.phase, result.label);
                    if (result.finished) {
                        window.location.href = result.url;
                        return;
                    }
                    if (result.stuck) {
                        note.textContent = config.labels.stuck;
                        retry.hidden = false;
                        return;
                    }
                }
            };
            retry.addEventListener('click', () => {
                retry.hidden = true;
                note.textContent = config.labels.keep_open;
                run('retry');
            });
            show(config.phase);
            if (config.phase === 'stuck') {
                note.textContent = config.labels.stuck;
                retry.hidden = false;
            } else {
                run('step');
            }
        })();
        JS;
    }

    private function document(string $title, string $nonce, string $body): string
    {
        $lang = $this->e($this->state['texts']['lang'] ?? 'en');

        return '<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.$this->e($title).'</title><style nonce="'.$nonce.'">'
            .':root{color-scheme:light dark;--bg:#fff;--ink:#1a1a1a;--muted:#6b6b6b;--line:#e3e3e3}'
            .'@media (prefers-color-scheme:dark){:root{--bg:#141414;--ink:#f2f2f2;--muted:#a3a3a3;--line:#333}}'
            .'body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,sans-serif}'
            .'main{width:min(28rem,100% - 2rem)}h1{font-size:1.5rem;line-height:1.25;margin:0 0 .75rem}p{margin:.5rem 0;color:var(--muted)}'
            .'.bar{height:6px;margin:1rem 0;border-radius:3px;background:var(--line);overflow:hidden}.bar span{display:block;height:100%;width:0;background:var(--ink);transition:width .3s}'
            .'button{margin-top:.75rem;padding:.5rem 1rem;border:0;border-radius:6px;background:var(--ink);color:var(--bg);font:inherit;cursor:pointer}'
            .'</style></head><body><main>'.$body.'</main></body></html>';
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
