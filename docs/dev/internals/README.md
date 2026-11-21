---
order: 30
---

# Internals

How parts of the core work, for contributors and for plugins that build on them.

- [Media library and photo picker](media-library.md): photos and albums, covers, system albums, the media library and the one photo picker.
- [Photo uploads](uploads.md): the chunked upload flow, limits and housekeeping.
- [Activity log internals](activity-log.md): levels, where entries come from, merging, batches, retention and the dashboard.
- [Notifications and e-mails](emails.md): notifications in the gallery (both bells), invitations, share, sign-up, site health and security notices, and how the gallery sends mail without a queue.
- [Admin UI conventions](admin-ui.md): lists and filters, closure parameters, navigation and labels, users on a record, global search, and the plugin and theme lists.
- [Speed](performance.md): work done once per request, the lean routes for photo files, photo sizes and the production caches that keep themselves up to date.
- [Setup wizard](setup-wizard.md): how `/setup` works before a database exists, when a copy counts as installed, and the steps.
- [One-click updates](updates.md): signed releases, the standalone updater, what an update replaces, rollback and the release process.
- [Installable app](web-app.md): the web app manifest, app icons, browser bar colour and the service worker with its offline page.
- [Search engines and link previews](search-engines.md): page details and Open Graph tags, preview images that work without cookies, share links, robots.txt and the sitemap.
