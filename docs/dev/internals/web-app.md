---
order: 80
---

# Installable app

The site can be installed as an app (a progressive web app): browsers offer to add it to the home screen or to install it, and it then opens in its own window. Everything is in core and works on plain shared hosting: a manifest served by Laravel, static icons, and one static service worker file. Admins turn it off with **Allow installing the site as an app** in Admin → Settings (`SiteSettings::WEB_APP`, read with `App\Support\WebApp::enabled()`, on by default).

## Pieces

| Piece | Where | What |
| --- | --- | --- |
| Head tags | `resources/views/partials/web-app.blade.php` | Manifest link, `apple-touch-icon`, `theme-color` and the service worker registration. Included by `partials.theme-head` (site) and by the `HEAD_END` render hook in `AdminPanelProvider` (admin) |
| Manifest | `/manifest.webmanifest` (`web-app.manifest`, `WebAppController@manifest`) | Built by `WebApp::manifest()` in the site's default language; 404 while the app is turned off |
| Icons | `public/web-app/` and `/app-icons/{size}.png` (`web-app.icon`) | The built-in mark as PNG (180 px white on dark for iPhone and iPad; 192 and 512 px black on white, also listed as `maskable`), or an icon made from the uploaded app icon (`SiteSettings::APP_ICON`) or favicon |
| Service worker | `public/sw.js` | Offline page and static files, see below |
| Offline page | `/offline` (`web-app.offline`, view `offline`) | Shown when a page cannot be loaded without a connection; themes override `views/offline.blade.php` |

The manifest and icon routes are in the `static` middleware group (`routes/files.php`): no session, the same for every visitor.

## Colours

`WebApp::themeColors()` reads the active theme's resolved tokens (`ThemeManager::resolvedVariables()`, so palettes and custom colours count): the browser bar is `--og-header-canvas` (falling back to `--og-canvas`) in light and dark mode, the splash screen background is the light `--og-canvas`. The head partial switches the `theme-color` meta tag whenever the `dark` class on `<html>` changes, on the site and in the admin.

## App icon

`WebApp::iconSource()` picks what the icon is made from: the uploaded app icon, else an uploaded favicon in PNG, JPEG or WebP. SVG favicons are not converted, because they could carry scripts or remote files, and GD cannot read ICO files; with those, or without any upload, the built-in mark is used.

Uploads stay on the private `local` disk under `branding/`. `WebApp::iconPath($size)` makes a square PNG in one of `WebApp::ICON_SIZES` (180 for iPhone and iPad home screens, 192 and 512 for the manifest) on first use and keeps it under `branding/app-icons/`, named after a hash of the source. An app icon is cropped; a favicon is fitted on white, because iOS shows transparent areas black. Replacing or removing either one in the settings calls `WebApp::forgetIcons()` (`AppSettings` and `GeneralSettings`, through `fileReplaced()`). If an upload cannot be read, the route answers with the built-in mark, so a home screen never falls back to a generated letter. Icons made from uploads are listed with purpose `any` only, because nothing guarantees a safe zone around the motif.

The built-in 180 px icon is white on a dark tile (`#1B1B1B`) on purpose: on a dark Home Screen, iOS turns a white icon background into dark glass and leaves a black mark as a faint outline. The 192 and 512 px icons stay black on white, because link previews and other platforms show them unchanged.

The built-in icons must not move to a `public/icons/` folder: the stock Apache configuration, common on shared hosting, has `Alias /icons/` pointing to Apache's own icons, so those URLs return 404 there.

## Service worker

`public/sw.js` is a static file, so no PHP runs for it and its scope is the app root, also when the gallery lives in a sub-folder. It is registered as `sw.js?v={version}&offline={url}` (`WebApp::serviceWorkerUrl()`). The version is a hash of the build manifest's time, the site name, the language, the active theme with its options and stylesheets, and the branding URLs; a new version installs a fresh copy, which fetches the offline page again and removes the old cache (`opengallery-{version}`).

What it does:

- **Navigations** always go to the server. Only if the request fails (no connection) does it answer with the stored offline page.
- **Static files** below the app root (`build/`, `css/`, `js/`, `fonts/`, `themes/`, `theme-media/`, `site/`, `web-app/`, `app-icons/`, `favicon*`) are answered from the cache and refreshed in the background. On install it also stores the styles, scripts and images that the offline page links to.
- **Everything else** is not touched: photos (`media/`, `pages/.../photos`), avatars, uploads, Livewire requests and all requests other than GET.

This keeps private content off the device: a person who logs out leaves nothing behind that another person on the same device could open. Do not widen the list of cached paths to anything that depends on who is asking.

While the app is turned off, the head partial unregisters any service worker whose script is `sw.js`, so devices that installed it earlier lose the offline page on their next visit.
