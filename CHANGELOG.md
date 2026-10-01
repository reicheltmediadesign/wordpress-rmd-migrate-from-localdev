# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.1] – 2026-10-01

### Removed

- Password protection for staging sites. The generated `.htaccess` block with `.htpasswd` caused "500 Internal Server Error" on some hosts (e.g. Strato) without an entry in the error log. Use the directory protection of your hosting panel instead. Settings saved for it are ignored.

## [0.2.0] – 2026-09-30

### Added

- Profile option **Search engines**: discourage or allow indexing on the target (sets `blog_public` in the dump), or keep the setting of the local site.
- **Password protection** per profile for staging sites: HTTP basic authentication via `.htaccess` and `.htpasswd` in the export, renewed with every upload. Only a bcrypt hash of the password is stored; `wp-cron.php` stays reachable.

## [0.1.0] – 2026-09-30

### Added

- Export profiles per target (site address, server path, table prefix, environment type, options).
- Database dump with serialization-safe search and replace of every spelling of the local address and file path; the local database is not modified.
- Complete copy of the site with `.gitignore`-style exclusion rules; linked plugin repositories are exported from `dist/<folder>.zip` or with their `.distignore`.
- `.htaccess` and `wp-config.php` template for the target, optional table prefix change.
- `MIGRATION.txt` report with next steps, statistics and remaining references to the local site.
- Step-by-step export with progress bar, continue and cancel; only available on local development sites.
- Automatic updates from GitHub releases.
