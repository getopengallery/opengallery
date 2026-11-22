---
order: 30
---

# Languages

OpenGallery comes in English and German. The site, the admin and the e-mails the gallery sends all follow the same language choice, and each person can pick their own.

## How the language is chosen

1. **The person's own choice.** Everyone with an account can pick a language in their [profile](../members-and-visitors/profile.md). Administrators with the permission "Manage users" can also set it in the person's account under **Users & access → Users** (field **Language**).
2. **The site's default language.** Everyone who has not picked a language, and every visitor who is not logged in, gets the **Default language** from **Settings → General**.

In both places, the empty choice is called "Site default" and shows the current default in brackets, for example "Site default (English)". People who keep it follow the default when you change it later. Languages are listed by their own name, for example "Deutsch".

The language of the visitor's browser is not used: visitors who are not logged in always see the default language.

## What follows the language

- Every text of the site and the admin: menus, buttons, forms and messages.
- Numbers, which are written the way that language writes them.
- E-mails, such as password reset links. They are always written in the recipient's language, even when someone else (for example an administrator) caused them.
- The activity log. Entries are shown in the language of whoever reads the log, not the language of the person who made the change.

## What stays as written

- Everything you write yourself: album titles and descriptions, photo titles, page content, the site name, and the texts you enter in the theme settings (landing page text, footer text, captions).
- Texts of themes and plugins that do not include a translation. They stay in English. The Default and Scrapbook themes and the Identity providers plugin are translated into German.
- This documentation, which is in English.
- Dates and times. They are always written day.month.year with a 24-hour clock, for example 20.11.2026 14:30, whatever the language. Only names of months and weekdays, such as on the activity calendar, are translated.

## Adding a language

New languages are added by a developer, by translating the gallery's language files. Once they are installed, the new language appears in **Settings → General** and in every profile. See [Languages and translations](../../dev/extending/translations.md#adding-a-language) in the developer guide.
