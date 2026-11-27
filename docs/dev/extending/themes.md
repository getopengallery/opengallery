---
order: 20
---

# Writing themes

A theme is a folder in `/themes/{slug}` that can change every part of the site visitors see: colours, corners and type, the layout of each section, the wording, error pages, e-mails and the settings form administrators fill in. Core never needs to be edited for this.

The Default theme (`themes/default`) is the reference: it uses every feature described here. Read it next to this page.

The Scrapbook theme (`themes/scrapbook`) is the second example and the opposite approach: it has no parent, replaces every visible block with its own markup, does nearly all of its styling in one plain stylesheet (`assets/scrapbook.css`) and adds tokens of its own (see [Theme tokens](#theme-tokens)). Use it to check that a change to core still works for a theme that shares nothing with the Default theme. `tests/Feature/Frontend/ScrapbookThemeTest.php` covers it.

The admin panel follows the active theme's colours and corners, but its layout is not themeable.

## Folder layout

```text
themes/polaroid/
  theme.json                        manifest (required)
  views/                            Blade overrides, same paths as resources/views
    blocks/auth-shell/polaroid.blade.php
    errors/404.blade.php
    vendor/notifications/email.blade.php
    vendor/mail/html/button.blade.php
  lang/
    en.json                         overrides of core wording
    en/settings.php                 the theme's own texts: __('theme-polaroid::settings.…')
  assets/
    polaroid.css                    served at /themes/polaroid/polaroid.css
```

Everything except `theme.json` is optional. Administrators install a theme by copying its folder or uploading it as a zip file of that folder (Themes → **Upload theme**); the folder name becomes the slug. See [Installing from a zip file](plugins.md#installing-from-a-zip-file) for how uploads are checked and unpacked. The loading code is `App\Extensions\ThemeManager` (`boot()` wires views, error pages, package views, e-mail components and language files).

## theme.json

```json
{
    "name": "Polaroid",
    "version": "1.0.0",
    "description": "Shown in the themes list.",
    "author": "You",
    "parent": "default",
    "variables": { "--og-primary": "#C2410C" },
    "variables_dark": { "--og-primary": "#FB923C" },
    "palettes": {},
    "blocks": { "photo-grid": "square" },
    "styles": ["polaroid.css"],
    "admin": { "primary": "#C2410C" },
    "settings": []
}
```

| Key | Meaning |
| --- | --- |
| `name` | Required. Shown in the admin. |
| `version`, `description`, `author` | Shown in the themes list. |
| `parent` | Slug of a theme to build on. Its views, blocks, palettes, variables and settings are inherited (see [Child themes](#child-themes)). |
| `variables` / `variables_dark` | Design tokens for light and dark mode (see [Tokens](#tokens)). |
| `palettes` | Named sets of tokens an administrator can pick (see [Palettes](#palettes)). |
| `blocks` | Fixed variant per block, for example `{"photo-grid": "square"}`. A settings field bound to the block wins over this. |
| `styles` | Extra stylesheets from `assets/`, loaded after the core CSS. |
| `admin.primary` | Optional primary colour for the admin panel (defaults to the theme's `--og-primary`). |
| `settings` | The settings form shown under Themes → gear icon (see [Settings](#settings)). |

## Tokens

Tokens are CSS variables named `--og-*`. Colours are written as hex in `theme.json` and turned into RGB channels, so Tailwind opacity modifiers work. Defaults live in `resources/css/app.css` and in the Default theme.

| Variable | Tailwind | Used for |
| --- | --- | --- |
| `--og-canvas` | `canvas` | Page background |
| `--og-surface` | `surface` | Cards, empty states, image placeholders |
| `--og-ink` | `ink` | Text |
| `--og-muted` | `muted` | Secondary text |
| `--og-line` | `line` | Borders and dividers |
| `--og-primary` | `primary` | Primary buttons and highlights |
| `--og-on-primary` | `on-primary` | Text on primary |
| `--og-danger` | `danger` | Errors and destructive buttons |
| `--og-on-danger` | `on-danger` | Text on danger |
| `--og-radius` | `rounded-og` | Corner radius (not a colour, any CSS length) |
| `--og-font-sans` | `font-sans` | Typeface stack (not a colour) |

`variables_dark` applies when the `<html>` element has the `dark` class. Only list the tokens that change in dark mode.

### Header colours

The header has its own set of tokens. By default each one follows the page token of the same name, so the header looks like the page:

| Variable | Follows | Used in the header for |
| --- | --- | --- |
| `--og-header-canvas` | `--og-canvas` | Background, and the text of buttons |
| `--og-header-surface` | `--og-surface` | Hover backgrounds |
| `--og-header-ink` | `--og-ink` | Logo, active links, button backgrounds |
| `--og-header-muted` | `--og-muted` | Other links and icons |
| `--og-header-line` | `--og-line` | Borders and dividers |

Every header layout puts the `og-header` class on its `<header>` element. Inside it, the page tokens are replaced by the header tokens (and primary buttons use `header-ink` on `header-canvas`), so a header layout keeps using the normal classes (`text-ink`, `hover:bg-surface`, `og-btn-primary`) and still follows a coloured header. Give your own header layouts the same class.

Set all five together, because a coloured background usually needs light text. The Default theme's Experimental palette uses the logo orange:

```json
"--og-header-canvas": "#FF5C35",
"--og-header-surface": "#FF7A57",
"--og-header-ink": "#FFFFFF",
"--og-header-muted": "#FFE1D7",
"--og-header-line": "#FF7A57"
```

Set them in `variables` only to use the same header in light and dark mode, or in `variables_dark` too to change it there. Menus that open from the header (the account menu, the mobile menu) use the header colours as well.

In views, use these tokens and the `og-*` component classes (`og-container`, `og-btn-primary`, `og-btn-secondary`, `og-btn-danger`, `og-input`, `og-link`, `og-prose`, `og-avatar`, `og-icon-btn`, `og-checkbox`, `og-safe-area`). `og-icon-btn` is a square icon-only button (44px on phones, 36px from `sm` up; colours come from the classes you add). `og-checkbox` styles a checkbox. `og-safe-area` pads a full-screen overlay by the device's safe-area insets, so it stays clear of the notch and the home bar; core's layouts set `viewport-fit=cover` for this, so a theme that replaces a layout keeps that in its viewport meta tag. Use `100dvh` rather than `100vh` for full-screen covers, so they do not overflow under a mobile browser's address bar. Never use raw Tailwind colours such as `gray-*` or `red-*`, and never hard-code hex colours.

Values must be hex colours or plain CSS values (letters, digits, spaces, commas, quotes, `%`, `.`, `/`, `(`, `)`, `-`). Anything else is dropped.

### Theme tokens

A theme may add tokens of its own. Any key named `--og-{name}` in `variables`, `variables_dark` or a palette is written out like the core tokens (hex turned into RGB channels), so palettes and `css_variable` fields can change it. Use them in the theme's stylesheet; they have no Tailwind class. The Scrapbook theme adds:

| Variable | Used for |
| --- | --- |
| `--og-pop-1` to `--og-pop-4` | Sticker, tape and doodle colours |
| `--og-on-pop` | Text on a sticker colour |
| `--og-print` | Photo frames and notebook paper |
| `--og-font-display` | Headline lettering |
| `--og-paper` | Page background, a CSS image such as `radial-gradient(...)` |

A select field bound to such a token lets administrators pick whole CSS values, for example Scrapbook's paper patterns:

```json
{ "key": "paper", "type": "select", "css_variable": "--og-paper", "default": "", "options": {
    "": "Dotted",
    "none": "Plain"
} }
```

Error pages are rendered without the theme's variables (see [Views](#views)). Give the theme's own tokens fallbacks in its stylesheet with zero specificity, so `theme.json` still wins everywhere else:

```css
:where(:root) {
    --og-pop-1: 255 79 139;
    --og-on-pop: 43 27 61;
}
```

### Fonts

The built CSS only bundles Hanken Grotesk. Other typefaces need either fonts already on the visitor's device (`system-ui, sans-serif`, `ui-serif, Georgia, serif`) or an `@font-face` in a theme stylesheet with the font files in `assets/`. Never load fonts from a CDN.

### Tailwind classes in theme views

Built assets ship with each release, and Tailwind only compiles classes it finds in `resources/views` and `themes/**` at build time. A theme installed later may only use classes that already exist in the build. To be safe, style a theme's own markup with the tokens and `og-*` classes, or with plain CSS in a stylesheet listed in `styles`:

```css
.polaroid-frame {
    background: rgb(var(--og-canvas));
    border-radius: var(--og-radius);
    box-shadow: 0 0 0 1px rgb(var(--og-line));
}
```

## Palettes

A palette is a named set of tokens. When an administrator picks it in a `palette` field, its `variables` and `variables_dark` are applied over the theme's own. A palette may set any token, not only colours. The Default theme's "Experimental" palette also sets `--og-radius`, `--og-font-sans` and the [header colours](#header-colours):

```json
"palettes": {
    "neutral": { "label": "Black and white" },
    "exp": {
        "label": "Experimental",
        "variables": { "--og-primary": "#FF5C35", "--og-radius": "14px", "--og-font-sans": "ui-serif, Georgia, serif" },
        "variables_dark": { "--og-primary": "#FF7A5A" }
    }
}
```

Fields bound to a CSS variable (`css_variable`) are applied after the palette, so an administrator's explicit choice wins. Give such a field an empty option (`"": "From the palette"`) as its default to let the palette decide until the administrator picks something.

## Views

Any view under `resources/views` can be replaced by a file with the same relative path in the theme's `views/` folder. A child theme's views win over its parent's, and both win over core.

The same folder also reaches the parts of the site that do not come from `resources/views`:

| Theme path | Replaces |
| --- | --- |
| `views/errors/{status}.blade.php` | Error pages (404, 403, 419, 500, 503, ...) |
| `views/offline.blade.php` | The page the installed app shows without a connection. It is kept on the device, so it must not show anything personal |
| `views/vendor/{namespace}/...` | Views of a package, the same path Laravel uses for published views, for example `vendor/notifications/email.blade.php` |
| `views/vendor/mail/html/...` and `views/vendor/mail/text/...` | E-mail layout and components (`button`, `header`, `footer`, `themes/default.css`, ...) |

An error page may be shown because the database is unavailable. Keep error pages self-contained: static markup and the theme's own CSS, without reading theme or site settings. The one exception is core's 403 page (`resources/views/errors/403.blade.php`), which uses the guest layout: a refusal only happens in a working application. An album that a logged-in person may not see has its own page, `albums/forbidden.blade.php`, which themes override like any other view; it gets no album data on purpose.

Album, password and page views pass search engine and link preview details to the layout (`<x-app-layout :meta="$meta">`). Keep that attribute when you override them, and include `partials.page-meta` in the `<head>` of a layout you replace (see [Search engines and link previews](../internals/search-engines.md)).

Prefer overriding a block (see [Blocks](blocks.md)) to overriding a whole page view: blocks are smaller and keep working when core pages change.

## Blocks

Pages are built from blocks such as `site-header`, `album-grid` or `photo-grid`. A theme changes a block by shipping its own `views/blocks/{name}.blade.php`, or adds layouts (variants) as `views/blocks/{name}/{variant}.blade.php` and picks one in `theme.json` (`blocks`) or with a settings field (`"block": "{name}"`).

The list of blocks with their props, how a variant is chosen, the variants of the Default theme and how core adds a new block are described in [Blocks](blocks.md).

## Settings

`settings` is a list of sections, each with fields. The admin builds the form from it (`App\Filament\Pages\ThemeSettings`); core never hard-codes theme options.

```json
"settings": [
    {
        "key": "login",
        "label": "Login",
        "description": "Optional text under the section title.",
        "fields": [
            { "key": "auth_layout", "type": "layout", "label": "Layout", "block": "auth-shell", "default": "polaroid", "options": { "default": "Centered", "polaroid": "Polaroid frame" } },
            { "key": "polaroid_tilt", "type": "toggle", "label": "Tilt the frame", "default": true, "visible_if": { "auth_layout": "polaroid" } }
        ]
    }
]
```

Field types:

| Type | Stores | Notes |
| --- | --- | --- |
| `text`, `textarea` | String | |
| `toggle` | Boolean | |
| `select` | Option key | Needs `options` |
| `layout` | Option key | Like `select`, shown as buttons. Use with `block` to choose a block variant. |
| `image` | Path on the private `local` disk | JPEG, PNG or WebP, with a wide preview in the admin. `max_kb` optional. Read with `Theme::image()`. |
| `pages` | List of page IDs | Read with `Theme::pages()`, which skips drafts and pages the visitor may not see. |
| `palette` | Palette key | Options come from `palettes` |
| `colors` | Light and dark hex per token | Needs `tokens`, for example `["primary", "canvas"]` |
| `css` | CSS | Added to every site page, escaped so it cannot leave its style tag |

Optional field keys: `label`, `help`, `default`, `visible_if` (`{"other_key": value}`), `block` (the field picks that block's variant), `css_variable` (the value is written to that `--og-*` variable).

Give every section a `key`: child themes match sections by it. Texts (section `label` and `description`, field `label` and `help`, option and palette names, the theme's `name` and `description`) are shown as written, or translated when they are a key in the theme's namespace, such as `"label": "theme-polaroid::settings.sections.login"`. See [Languages and translations](translations.md#writing-themes).

Values are stored per theme in the `settings` table (`theme.options.{slug}`). Read them in Blade:

```blade
@php($tilt = \App\Support\Theme::enabled('polaroid_tilt'))
<img src="{{ \App\Support\Theme::image('auth_image') }}" alt="">
{{ \App\Support\Theme::option('auth_caption', 'Welcome') }}
@foreach (\App\Support\Theme::pages('footer_pages') as $page) ... @endforeach
```

The core fallback blocks read a few well-known keys, so a theme that declares them gets that behaviour for free: `header_pages`, `header_mode_toggle`, `header_sticky`, `footer_text`, `footer_pages`, `landing_heading`, `landing_text` and `landing_show_register`.

## Child themes

A theme with `"parent": "{slug}"` builds on another theme (up to five levels):

- views and block variants: the child's files win, everything else comes from the parent;
- `variables`, `variables_dark` and `palettes`: merged, the child wins per key;
- `settings`: merged over the parent's form.

Settings merge like this:

- sections match by `key`, or by `label` when they have no key; fields match by `key`;
- a matching section or field takes the child's attributes over the parent's, and a field's `options` are added to the parent's;
- new sections and fields are added at the end;
- `"remove": true` drops the parent's section or field.

This child of the Default theme adds a login layout with its own option, renames a section and drops the custom CSS editor:

```json
{
    "name": "Polaroid",
    "parent": "default",
    "settings": [
        {
            "key": "auth",
            "label": "Photo booth login",
            "fields": [
                { "key": "auth_layout", "default": "polaroid", "options": { "polaroid": "Polaroid frame" } },
                { "key": "polaroid_tilt", "type": "toggle", "label": "Tilt the frame", "default": true }
            ]
        },
        { "key": "custom_css", "remove": true }
    ]
}
```

Together with `views/blocks/auth-shell/polaroid.blade.php`, the login layout buttons now show Centered, Form and image, Form on an image and Polaroid frame.

Saved values belong to one theme: a child theme starts from the field defaults, not from the values saved for its parent.

## Wording

`lang/{locale}.json` overrides any text core writes with `__()`, keyed by the English text, in any language core ships:

```json
{
    "Albums": "Galleries",
    "My albums": "My galleries",
    "This album has no photos yet.": "Nothing here yet."
}
```

Theme files win over core, and a child theme over its parent. Use this instead of copying a view just to change its words. New user-facing text in core is always written with `__()` so themes can change it this way.

The theme's own texts (its settings form, words in its own views) belong in `lang/{locale}/{group}.php` under the namespace `theme-{slug}`, not in the JSON file. A child theme changes its parent's texts with `lang/vendor/theme-{parent}/{locale}/{group}.php`. Details: [Languages and translations](translations.md).

## Upgrading themes

Recent changes that affect existing themes:

- Multilingual support: the Default theme's sections now have keys (`colours`, `header`, `footer`, `albums`, `landing`, `auth`, `custom_css`) and its labels are translation keys. A child theme that matched a Default section by its English `label` must use the `key` instead.
- A theme's `lang/{locale}/{group}.php` files are now its own namespaced texts (`theme-{slug}::group.key`); they no longer override core's group files such as `validation.php`. Core wording is still changed with `lang/{locale}.json`.

- The extra header, footer, landing, login and photo layouts moved from core into the Default theme. A theme that used them through `blocks` or a `layout` field needs `"parent": "default"` or its own copies.
- A child theme's `settings` are now merged over the parent's instead of replacing them. A child that copied its parent's whole form keeps working, but can be shortened to its differences.
- The Default theme's Slate, Harbour and Plum palettes were removed. Sites that chose one fall back to Black and white.
- New tokens `--og-danger` and `--og-on-danger`, and the header tokens `--og-header-*`. Custom header layouts should add the `og-header` class to their `<header>` element.
- The album list (`albums/index.blade.php`) now also receives `$search` (the current search, or an empty string) and shows the `album-search` block. A theme that replaces this view should render the block and the search result line; see core's view.
- The lightbox items carry more keys (`id`, `description`, `taken`, `download`, `favorite`, `info`, `license`, `position`, `total`, `page`, `previousPage`, `nextPage`, `previousItems`, `nextItems`). Layouts that pass `$items->pluck('lightbox')` to `ogLightbox()` get them without changes. `license` is `{name, title, url}` or null (only filled while licensing and the photo information are on, see `App\Support\Licensing::displayFor()`); a theme that replaces the `lightbox` block should print it in its "i" panel, as core's `resources/views/blocks/lightbox.blade.php` does, and count it when deciding whether the "i" button shows.
- Text props of `<x-block>` are no longer escaped twice. A block that printed one with `{!! !!}` to undo the double escaping must use `{{ }}` now.
- The `album-grid` block now prints its slot (extra leading `<li>` items) before the albums, and the new `favorites-tile` block is the Favorites entry on "My albums". A theme that overrides `album-grid` should print `{{ $slot ?? '' }}` at the start of its list; the Default theme's `large` variant and the Scrapbook theme already do.
- New classes `og-icon-btn`, `og-checkbox` and `og-safe-area`; core's layouts now set `viewport-fit=cover`. A theme with its own layout should do the same, and header or lightbox icon buttons should be at least 44px on phones (`og-icon-btn`).
- Core ships `errors/403.blade.php` and `albums/forbidden.blade.php`; themes can replace both.
