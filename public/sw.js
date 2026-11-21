/*
 * OpenGallery service worker (registered by resources/views/partials/web-app.blade.php,
 * see App\Support\WebApp).
 *
 * - Pages always come from the server. Only when that fails (no connection)
 *   is the offline page shown instead.
 * - Static files (built assets, admin styles and scripts, theme files,
 *   branding, fonts, icons) are kept on the device and refreshed in the
 *   background.
 * - Photos, avatars, uploads, the admin's Livewire requests and everything
 *   that is not a GET request are never touched, so nothing private is stored.
 *
 * The registration URL carries `v` (changes with the build, theme, branding
 * and language) and `offline` (the offline page). A new `v` installs a fresh
 * copy and removes the old cache.
 */

const params = new URL(self.location.href).searchParams;
const CACHE = `opengallery-${params.get('v') || 'dev'}`;
const OFFLINE_URL = params.get('offline') || new URL('offline', self.registration.scope).href;
const ROOT = new URL(self.registration.scope);

/** Folders (below the app root) whose files are the same for every visitor. */
const STATIC_PATHS = ['build/', 'css/', 'js/', 'fonts/', 'themes/', 'theme-media/', 'site/', 'web-app/', 'app-icons/', 'favicon'];

const isStatic = (url) => url.origin === ROOT.origin
    && url.pathname.startsWith(ROOT.pathname)
    && STATIC_PATHS.some((path) => url.pathname.slice(ROOT.pathname.length).startsWith(path));

/** Keeps a good, same-origin response; ignores the rest. */
const keep = async (cache, request, response) => {
    if (response.ok && response.type === 'basic' && !response.redirected) {
        await cache.put(request, response.clone());
    }

    return response;
};

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);

        try {
            const response = await fetch(OFFLINE_URL, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok || response.redirected) {
                return;
            }

            const html = await response.clone().text();
            await cache.put(OFFLINE_URL, response);

            // The offline page's own styles, scripts and images, so it looks right without a connection.
            const assets = [...html.matchAll(/<(?:link|script|img)\b[^>]*?\b(?:href|src)="([^"]+)"/g)]
                .map((match) => new URL(match[1].replaceAll('&amp;', '&'), OFFLINE_URL))
                .filter(isStatic);

            await Promise.all(assets.map((url) => fetch(url).then((r) => keep(cache, url.href, r)).catch(() => {})));
        } catch {
            // Offline while installing: the next visit tries again.
        }
    })().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names
            .filter((name) => name.startsWith('opengallery-') && name !== CACHE)
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => {
            const offline = await caches.match(OFFLINE_URL, { cacheName: CACHE });

            return offline || Response.error();
        }));

        return;
    }

    const url = new URL(request.url);
    if (!isStatic(url)) {
        return;
    }

    // Stale while revalidate: the kept copy at once, a fresh one for next time.
    event.respondWith((async () => {
        const cache = await caches.open(CACHE);
        const cached = await cache.match(request);
        const network = fetch(request).then((response) => keep(cache, request, response));

        if (cached) {
            event.waitUntil(network.catch(() => {}));

            return cached;
        }

        return network;
    })());
});
