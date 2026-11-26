---
paths:
  - "lang/**"
  - "resources/views/**"
  - "themes/**"
  - "plugins/**"
  - "app/Filament/**"
  - "app/Support/Locales.php"
  - "app/Http/Middleware/SetLocale.php"
---

## Languages
- Core owns every application text: `__('English text')` with the English text as key, translated in `lang/{locale}.json` (+ `lang/{locale}/{auth,passwords,pagination,validation}.php`). English is the source and has no JSON file. Every user-facing string in PHP, Blade and Filament goes through `__()` / `trans_choice()` with `:placeholders`, never concatenation; JS texts are passed from PHP. Add each new key to every `lang/*.json`; `tests/Feature/TranslationFilesTest.php` checks keys, placeholders and renders the admin + site in German.
- Plugins and themes never add core keys. Their own texts are namespaced: plugins `plugins/{slug}/lang/{locale}/{group}.php` as `__('{slug}::group.key')` (registered by `PluginManager`), themes `themes/{slug}/lang/{locale}/{group}.php` as `__('theme-{slug}::group.key')` (registered by `ThemeManager`). Manifest texts (theme.json / plugin.json names, descriptions, settings labels, options, palettes) go through `Manifest::text()` / `label()` / `summary()`: a namespaced key is translated, plain text is shown as written. Theme `lang/{locale}.json` overrides core copy and `lang/vendor/{ns}/...` a plugin's or parent's texts (`App\Extensions\TranslationLoader` override paths; theme lang dirs are never normal lookup paths).
- Language choice: site default `SiteSettings::LOCALE` (Admin → Settings, `config('gallery.default_locale')`), per user `users.locale` (profile / user form, null = default), applied by `App\Http\Middleware\SetLocale` (web + panel, also `Number::useLocale`). `App\Support\Locales` lists languages (English + core `lang/*.json`). `User` implements `HasLocalePreference`, so mail goes out in the recipient's language. Filament: static label props cannot call `__()`; override `getModelLabel()` / `getPluralModelLabel()` / `getNavigationLabel()` / `getTitle()`; navigation groups are `App\Filament\Navigation\NavigationGroup` (lazy labels). Stored texts (activity log fields, option values) are stored in English and translated when shown. Developer reference: `docs/dev/extending/translations.md`.

