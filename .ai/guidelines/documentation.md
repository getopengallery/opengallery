# Documentation (`/docs`)

`/docs` is part of the deliverable for every change. This overrides the general rule to create documentation files only when asked: pages under `/docs` are always kept up to date (new ones included); outside `/docs`, create documentation files only when asked. Full writing rules are in `.claude/rules/docs.md`, which loads when you open any file under `docs/`. Hand doc updates to the `docs-writer` subagent with a short description of the change; include its report in your summary.

## When to update
- User/admin-visible change (settings, permissions, routes, share links, UI, install steps) → update `docs/user/`.
- Developer-facing change (new enum value, extension point, required config, access logic, plugin hook, build/queue requirement, `theme.json`/`plugin.json` field) → update `docs/dev/`.
- Bug fix with no behavior impact, or pure copy/styling change → usually no doc change; say so in your summary and flag stale screenshots.

## Reporting
In your final summary, list the doc files you added or updated, any screenshot placeholders (`<!-- TODO: screenshot ... -->`) and stale screenshots, or why you skipped a doc update.
