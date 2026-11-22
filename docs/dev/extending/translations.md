---
order: 40
---

# Languages and translations

OpenGallery uses Laravel's standard translation system. The application (core) ships every text it needs itself; themes and plugins only add their own texts, or change core's wording, through their own language files. This page explains who owns which text, how languages are chosen and how to add one.

## Who owns which text

| Owner | Where | Used as |
| --- | --- | --- |
| Core | `lang/{locale}.json` | `__('Save settings')`, keyed by the English text |
| Core (framework messages) | `lang/{locale}/{auth,passwords,pagination,validation}.php` | `__('validation.required')` |
| A plugin | `plugins/{slug}/lang/{locale}/{group}.php` | `__('{slug}::group.key')` |
| A theme | `themes/{slug}/lang/{locale}/{group}.php` | `__('theme-{slug}::group.key')` |
| A theme changing core wording | `themes/{slug}/lang/{locale}.json` | overrides the core key of the same name |
| A theme changing a plugin's or parent theme's wording | `themes/{slug}/lang/vendor/{namespace}/{locale}/{group}.php` | overrides `{namespace}::group.key` |

The rule of thumb: core texts have no namespace, everything a plugin or theme brings has its own namespace. A plugin never adds texts to core's JSON files, and core never contains a plugin's or theme's texts.

English is the source language. Core keys are the English texts themselves, so English needs no `lang/en.json`. Plugins and themes use short keys, so they ship `lang/en/*.php` with their English texts.

## Choosing the language

- **Site default**: Admin → Settings → General (`App\Support\SiteSettings::LOCALE`). `SiteSettings::apply()` puts it in `config('gallery.default_locale')`; `APP_LOCALE` in `.env` is only the initial default.
- **Per user**: `users.locale`, chosen in the profile or by an administrator in the user form. Empty means "site default".
- **Per request**: `App\Http\Middleware\SetLocale` (web group and admin panel) sets `App::setLocale()` and `Number::useLocale()` from `App\Support\Locales::for($user)`. Guests get the site default. Dates do not follow the language: they are always `DD.MM.YYYY HH:MM` (`App\Support\DateFormat`, see [Admin UI conventions](../internals/admin-ui.md#lists-and-filters)); only month and weekday names, such as the activity calendar's labels, are translated.
- **E-mails**: `User` implements `HasLocalePreference`, so notifications and mails are sent in the recipient's language, also from queued jobs.

`App\Support\Locales::available()` lists the selectable languages: English plus every `lang/{locale}.json` core ships. Themes and plugins cannot add a language on their own; a language they do not translate falls back to their English texts.

## Writing core code

- Wrap every user-facing text in `__()` (or `trans_choice()` for counts), in PHP and in Blade. Write the English text as the key and use `:placeholders` instead of concatenation, so translators can reorder the sentence:

```php
__('Sharing ended :date', ['date' => $album->access_expires_at->isoFormat('ll')])
trans_choice(':count photo removed|:count photos removed', $count)
```

- Filament: pass translated labels (`->label(__('Name'))`), also for fields with `hiddenLabel()` (screen readers use the label). `AppServiceProvider` switches on `translateLabel()` for every form field, table column, filter and infolist entry, so a label Filament derives from a name (`created_at` → "Created at") goes through `__()` too: add it to every `lang/{locale}.json` or, better, give the field an explicit label. Labels that are translated already elsewhere (theme settings built from `theme.json`) use `->translateLabel(false)`. Static properties cannot call `__()`, so override the getters instead (`getModelLabel()`, `getPluralModelLabel()`, `getNavigationLabel()`, `getTitle()`, `getSubheading()`). Navigation groups come from `App\Filament\Navigation\NavigationGroup`.
- Texts for JavaScript: pass them from PHP (see `MediaPicker::uploaderMessages()`), never hard-code them in scripts. In Blade, use `@js(__('...'))`, which escapes the text for HTML attributes too. For a number only known in the browser, pass both plural forms with `:count` left in and replace it in the script, as `media-picker-field.blade.php` does: `@js(trans_choice(':count photo|:count photos', 2, ['count' => ':count']))`.
- Array keys and labels that are shown to people (for example `getGlobalSearchResultDetails()`) go through `__()` as well. `TranslationFilesTest` only finds texts that are passed to `__()`, so it cannot spot a forgotten call.
- Text that is stored and shown later (activity log field names, option labels) is stored in English and translated when shown (`ActivityEvent::details()`), so every reader sees their own language.
- Add every new key to every `lang/{locale}.json`. `tests/Feature/TranslationFilesTest.php` fails when a key is missing, when a translation drops a `:placeholder`, or when an admin or site page, or the page editor's photo and gallery block forms, shows an untranslated text in German (including derived labels).

## Writing plugins

Put your texts in `plugins/{slug}/lang/{locale}/{group}.php`. `App\Extensions\PluginManager` registers the folder for every installed plugin under the namespace `{slug}`, so you do not call `loadTranslationsFrom()` yourself:

```php
// plugins/watermark/lang/en/settings.php
return [
    'title' => 'Watermark',
    'opacity' => 'Opacity',
];
```

```php
TextInput::make('opacity')->label(__('watermark::settings.opacity'))
```

Texts in `plugin.json` (`name`, `description`) may be a translation key in your namespace. Core shows plain text as written and translates keys:

```json
{
    "name": "watermark::plugin.name",
    "description": "watermark::plugin.description"
}
```

The identity providers plugin (`plugins/identity-providers`) is a complete example.

## Writing themes

A theme's own texts live in `themes/{slug}/lang/{locale}/{group}.php`, under the namespace `theme-{slug}`. Every text in `theme.json` (name, description, section and field labels, help texts, option and palette names) may be such a key:

```json
{
    "key": "header",
    "label": "theme-polaroid::settings.sections.header",
    "fields": [
        { "key": "polaroid_tilt", "type": "toggle", "label": "theme-polaroid::settings.tilt" }
    ]
}
```

Plain text in `theme.json` is shown exactly as written in every language; it is never looked up in core's translations. The Default theme (`themes/default/lang`) translates its whole settings form this way.

To change core wording, add `lang/{locale}.json` with the core keys you want to change (see [Writing themes](themes.md#wording)). A child theme can override its parent's texts with `lang/vendor/theme-{parent}/{locale}/{group}.php`. Overrides of the active theme are read after core's files, and a child's after its parent's (`App\Extensions\TranslationLoader`).

A theme cannot override core's framework groups (`validation.php` and so on) any more; only core's JSON texts.

## Adding a language

1. Copy `lang/de.json` to `lang/{locale}.json` (for example `fr.json`) and translate the values. Keep the keys and every `:placeholder`.
2. Add `lang/{locale}/auth.php`, `passwords.php`, `pagination.php` and `validation.php` (copy the German files or Laravel's English ones from `vendor/laravel/framework/src/Illuminate/Translation/lang/en`).
3. Translate the shipped themes and plugins: `themes/*/lang/{locale}/` and `plugins/*/lang/{locale}/`, same file names as `en`.
4. Run `php artisan test --filter=TranslationFiles`.

The language then appears in Admin → Settings → General and in every profile. The admin panel's own texts come with Filament, which ships many languages.
