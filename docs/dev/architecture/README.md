---
order: 10
---

# Architecture

How OpenGallery is put together: the boot sequence and requests, who may see what, the database, and how site settings reach the code. Read these before changing core behaviour.

- [Architecture overview](overview.md): constraints, folders, boot sequence, routes, private files, storage and runtime settings.
- [Access model](access-model.md): permissions and roles, album visibility, shares, passwords and end dates, policies, and photos on pages.
- [Data model](data-model.md): every table, its columns and relations, and the pivots that feed the activity log.
- [Site settings internals](site-settings.md): setting keys, how they reach config, e-mail, uploads, branding, and adding a setting.
- [Security measures](security.md): the site address, security headers, sessions, hidden accounts, rate limits and location data.
