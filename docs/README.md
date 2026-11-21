# OpenGallery documentation

OpenGallery is a self-hosted photo gallery that runs on plain shared PHP hosting. This documentation is written in Markdown, lives in the `docs` folder of the project and is shown in the admin under **Help → Documentation**.

It has two guides, one for each audience.

## User guide

For people who run the gallery through its web interface: site owners, administrators and editors. No code involved.

- [User guide overview](user/README.md)
- [Getting started](user/getting-started/README.md): installing and upgrading, first steps, and finding your way around the admin.
- [Albums, photos and pages](user/content/README.md): albums, the media library, sharing and content pages.
- [Administration](user/administration/README.md): site settings, users and roles, languages, themes, plugins and the activity log.
- [Members and visitors](user/members-and-visitors/README.md): the site as visitors see it, logging in, "My albums" and profiles.

## Developer guide

For developers who customize or extend OpenGallery with themes and plugins, or work on the code.

- [Developer guide overview](dev/README.md)
- [Architecture](dev/architecture/README.md): how the code is put together, the access model, the data model and site settings.
- [Extending OpenGallery](dev/extending/README.md): plugins, themes, blocks, languages and translations.
- [Internals](dev/internals/README.md): media library and photo picker, uploads, activity log and admin UI conventions.
- [Working on the core](dev/contributing/README.md): frontend build, testing, commands and schedule, demo data and writing documentation.
