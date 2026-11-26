---
paths:
  - "docs/**"
---

# Documentation (`/docs`)

`/docs` is a living, in-repo documentation set and part of the deliverable for every change — not optional. This overrides the general rule to create documentation files only when asked: pages under `/docs` are always kept up to date (new ones included); outside `/docs`, create documentation files only when asked. After implementing and testing any behavior change, proactively update `/docs` before finishing. The docs are rendered in-app from these Markdown files, so they must be clean, valid Markdown with sane syntax.

## Audience split (two folders only)
- **`/docs/user/`** — everything an end user or system admin does through the running application: installing/upgrading, Admin → Settings, albums, photos, sharing (unlisted links, passwords, expiry), profiles/avatars, themes as seen in the UI, pages, permissions as configured in the admin, activity log settings. Plain language, no code, no internals. Admin-level operations belong here, not in `dev/`.
- **`/docs/dev/`** — everything a developer touches to customize or extend the system: plugin and theme authoring (`plugin.json`, `theme.json`, extension points, events, `LoginOptions`, `PluginSettings`), frontend build (`public/build`, Vite, Node version), architecture of the access model, extension contract, upgrade notes for developers. Code-level detail is fine here.

Do not create a third audience folder. If something is unclear, decide by *who acts on it*: an admin clicking through the UI → `user/`; a developer writing code or JSON → `dev/`.

## Structure and order
- Inside `user/` and `dev/`, pages live in topic sub-folders (e.g. `user/content/albums.md`, `dev/extending/themes.md`). Each sub-folder has a `README.md`: its `#` heading is the folder's label in the navigation and it lists the folder's pages for readers.
- Order is never hard-coded and never part of a file name. Every page except `docs/README.md` starts with front matter, before the `#` heading:
  ```markdown
  ---
  order: 20
  ---
  ```
  Pages sort by `order` within their folder (steps of 10, so new pages fit in between); a folder sorts by its README's `order`.
- Add a sub-folder only for a group of related pages a reader would look for together; keep to one level inside each guide.

## Markdown & rendering rules
The docs are rendered by the application, so:
- Write **clean, valid CommonMark Markdown**. One `#` H1 per file (the title), then `##`/`###` for structure. Do not skip heading levels.
- **No raw HTML** unless truly unavoidable; prefer Markdown. If you must use HTML, keep it minimal and well-formed.
- Use **fenced code blocks with a language tag** (```bash, ```php, ```json, ```blade). No indented code blocks.
- Use **relative links between docs** (e.g. `../administration/settings.md#albums`), not absolute URLs, so they work in-app. `DocumentationTest` fails on broken links, anchors and images; when you move a page or rename a heading, fix its inbound links in the same change.
- Keep tables simple (no nested block content inside cells). Use lists for anything complex.
- Don't rely on the frontend build for docs: no `<script>`, no inline styles, no `<style>` blocks.
- Prefer plain, unicode-safe text; avoid exotic characters that may not render in every font.

## Screenshots
Screenshots are encouraged to illustrate UI steps, especially in `user/` docs.
- Store images under **`/docs/assets/`** (shared) — e.g. `docs/assets/albums-upload.png`. Create the folder if missing.
- Reference with Markdown, relative to the current doc: from `docs/user/content/albums.md` that is `![Uploading photos](../../assets/albums-upload.png)`.
- Use descriptive alt text (what the user should see / click). Alt text is what appears if the image fails to render.
- Filenames: lowercase, hyphenated, `<area>-<action>.png`. Never spaces, never non-ASCII.
- You cannot take screenshots yourself. When a screenshot would clearly help but none exists, **insert a placeholder** and flag it in your summary:
  `![Upload dialog](../../assets/albums-upload.png) <!-- TODO: screenshot of the upload dialog -->` (only images with such a TODO on the same line may point to a missing file)
  Do not invent image files or reference remote URLs.
- Keep screenshots up to date: if a UI change makes a screenshot misleading, flag the affected image(s) in your summary for re-capture rather than silently leaving it.

## How to update
- First read `docs/README.md` and the README of the sub-folder for the area you touched, then the pages it links.
- Prefer editing existing files in place. Create a new file only when there is no sensible home; give it an `order` and link it from its folder's `README.md` (and from `docs/README.md` when you add a sub-folder).
- Match the tone/structure of neighbouring docs. `user/` docs must stay free of class names, file paths, and internals.
- Keep docs accurate: rewrite or remove stale statements rather than appending contradictory notes. Update examples (config keys, commands, UI labels) that no longer match.
- Reference the real source of truth (`App\Enums\Permission`, `SiteSettings` keys, `config/gallery.*`, route names, `theme.json`/`plugin.json` fields) so devs can trace it.
- Do not invent features. Only document what exists in the codebase after your change.


## How the docs are built
- Docs (`/docs`, shown in Admin → Help → Documentation): `docs/user/` and `docs/dev/` hold topic sub-folders. Nothing about their order is hard-coded: every page except `docs/README.md` starts with front matter `---\norder: 20\n---` (steps of 10), a folder takes its place from its `README.md`'s `order` and its label from that README's `#` heading (`App\Support\Documentation`). `DocumentationTest` checks orders, links, anchors and images. Developer reference: `docs/dev/contributing/documentation.md`.
