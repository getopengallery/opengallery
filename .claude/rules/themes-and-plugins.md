---
paths:
  - "themes/**"
  - "plugins/**"
  - "resources/views/**"
  - "resources/css/**"
  - "tailwind.config.js"
  - "app/Extensions/**"
  - "app/Support/Theme*.php"
  - "app/Support/AdminTheme.php"
  - "app/Filament/RichEditor/**"
  - "app/Filament/Pages/ThemeSettings.php"
  - "app/Models/Page.php"
---

## Extensions
- Themes: `/themes/{slug}/theme.json` (+ optional `views/`, `lang/`, `assets/`). Every visitor-facing surface is themeable through these seams (wired in `ThemeManager::boot()`, child theme before parent before core); use them, don't add new mechanisms: `views/` overrides any `resources/views` file, `views/errors/*` error pages, `views/vendor/{namespace}/*` package views, `views/vendor/mail/*` e-mail components, `lang/{locale}.json` any core `__()` copy, `lang/{locale}/*.php` the theme's own `theme-{slug}::` texts (see Languages). Developer reference: `docs/dev/extending/themes.md`.
- Tokens: CSS variables `--og-*` (hex in theme.json, converted to RGB channels; defaults in `resources/css/app.css` and `themes/default/theme.json`) mapped to Tailwind `canvas, surface, ink, muted, line, primary, on-primary, danger, on-danger`, plus `--og-radius` (`rounded-og`) and `--og-font-sans` (`font-sans`). Header colours `--og-header-{canvas,surface,ink,muted,line}` default to the page tokens; every header layout's `<header>` carries `og-header`, which swaps the page tokens for them, so header markup keeps using the normal token classes. `variables_dark` applies under `html.dark`; palettes may set any token. Use these tokens and the `og-*` component classes (`og-container`, `og-btn-primary`, `og-input`, ...) in frontend views, never raw `gray-*`/`red-*`/hex. A new colour need gets a new token (app.css + tailwind.config.js + Default theme), not a hard-coded value.
- Theme settings: themes declare a settings form in theme.json `settings` (field types text/textarea/toggle/select/layout/image/pages/palette/colors/css; `block`, `css_variable`, `visible_if`, `default`). A child theme's settings merge over its parent's (sections by `key`/`label`, fields by `key`, `options` added, `"remove": true` drops). Values live in Setting `theme.options.{slug}`; read them with `App\Support\Theme::option()/image()/pages()`. The admin form is generic (`App\Filament\Pages\ThemeSettings`); do not hard-code theme options in core. Theme images are on the private `local` disk under `themes/{slug}` and served by `ThemeAssetController::upload`. Custom CSS must stay escaped (`ThemeManager::customCss`).
- Pages (`App\Models\Page`, `/pages/{slug}`): published pages are public even with public albums off; `members_only` needs login. Render bodies only through `RichContentRenderer` (sanitizes).
- Page content blocks: `App\Filament\RichEditor\PhotoBlock` / `GalleryBlock` (custom rich editor blocks; config `photo_id` / `photo_ids`). On the site they render through the themeable blocks `page-photo` / `page-gallery` with the lightbox, and images use `route('pages.photo', [page, photo, conversion])`, which only serves photos that `Page::embeddedPhotoIds()` finds in a page the visitor may see. Placing a photo on a published page publishes it there only. Photos are chosen with `MediaPickerField` (uploads go into the private album `Album::pageImages()`).
- Page building blocks are rendered with `<x-block name="..." :prop="..." />` (views in `resources/views/blocks`). `ThemeManager::blockView()` resolves: settings field with `"block"` > theme.json `blocks` > `blocks/{name}/{variant}` if it exists > `blocks/{name}`. Core ships one plain fallback per block; extra layouts belong to themes (the Default theme ships its header/footer/landing/login/album/photo variants in `themes/default/views/blocks`). Pagination (`->links()`) renders the `pagination` block. Put new reusable page sections in a block, not inline in a page.
- Dark mode uses the Filament storage key `filament:theme:mode` and the `.dark` class, shared by site and admin. The admin follows the theme settings too: Filament's primary and gray palettes come from `App\Support\AdminTheme` (resolved tokens incl. palette and custom colours), and corners/dark-mode primary buttons from `resources/views/filament/partials/theme-styles.blade.php`. Never hard-code admin colours.
- Plugins: `/plugins/{slug}/plugin.json` with `autoload` + `provider`; loaded by `App\Extensions\PluginManager` only while enabled. Plugins hook in via events/service providers and must not require core edits. If you need a new extension point, add an event or config hook to core rather than special-casing a plugin.
- Plugins are loaded in the register phase (`PluginManager::register()` from `AppServiceProvider::register()`); their `boot()` may use Eloquent, their `register()` must not. Extension points: plugin.json `settings` (page extending `App\Filament\Pages\PluginSettingsPage`, slug `plugins/{slug}/settings`), `App\Extensions\PluginSettings` (encrypt secrets), `App\Extensions\LoginOptions` (login buttons / default login; `/login?local=1` must always show the password form). `GALLERY_PLUGINS` force-enables plugins; tests enable `identity-providers` via phpunit.xml.
- Blade: never mix inline `@php(...)` with `@php ... @endphp` blocks in one view; Blade then swallows the markup in between.
- Runtime settings (active theme, enabled plugins) are in the `settings` table via `App\Models\Setting::get()/put()`.

