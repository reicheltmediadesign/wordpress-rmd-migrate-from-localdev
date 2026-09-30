# RMD Migrate from Localdev

WordPress plugin that prepares a local development site for upload to a staging or production server. It writes a copy of all files and a database dump in which every address and path of the local site is replaced by that of the target, safe for serialized data. Uploading stays with you: files via SFTP, database via phpMyAdmin. Built by [reichelt media.design](https://reicheltmedia.design).

## Features

- **Serialization-safe search and replace.** Serialized PHP values (widgets, theme and plugin settings) are parsed, rewritten and re-serialized with correct lengths. Objects are never instantiated, so classes that are not loaded survive unchanged. Serialized data nested inside strings is handled as well.
- **Every spelling of the local address:** `http://` and `https://`, protocol-relative (`//localhost/site`), without scheme (`localhost/site`), JSON-escaped (`http:\/\/localhost\/site`), URL-encoded, and links relative to a local subfolder (`href="/site/…"`, `url(/site/…)`).
- **Local file paths** (`C:\xampp\htdocs\site`, `C:/xampp/…`, JSON-escaped) are replaced by the server path of the target.
- **The local database is never changed.** Replacements happen while writing the dump.
- **Profiles** for several targets, e.g. "Development" and "Production", each with its own address, server path, table prefix and options.
- **Complete site copy** with sensible exclusions (`.git`, `node_modules`, caches, backups, logs, Markdown files in the WordPress folder) and your own rules in `.gitignore` syntax.
- **Linked plugin repositories** (junctions/symlinks in `wp-content/plugins`): if the repository contains a built release zip (`dist/<folder>.zip`), that zip is used; otherwise its `.distignore` applies, so no development files end up on the server.
- **Ready for the target:** `.htaccess` with the right `RewriteBase`, a `wp-config.php` template with placeholders for the database credentials, new security keys, debugging off and `WP_ENVIRONMENT_TYPE` set.
- **Search engine visibility** per profile: discourage indexing on development and staging sites, allow it on production, or keep the local setting.
- **Password protection for staging** (HTTP basic authentication): the export writes the rules into `.htaccess` and a `.htpasswd` into `files/`, so the protection is renewed with every upload instead of being overwritten by it. Only a bcrypt hash of the password is stored.
- **Optional table prefix change**, including the keys WordPress stores with the prefix (`<prefix>user_roles`, `<prefix>capabilities`, …).
- **Leaner dump:** transients, this plugin's own data and optionally revisions and spam are left out; tables like Yoast's indexables are exported empty because the plugin rebuilds them.
- **Compatible dump** for phpMyAdmin: gzip-compressed, batched inserts, binary data as hex, collations of MySQL 8 and new MariaDB versions replaced for older servers (optional).
- **Report** (`MIGRATION.txt`) with next steps, rows and changed values per table, and every value that still refers to the local site.
- **Runs in short steps** with a progress bar, so PHP time limits do not matter. An interrupted export can be continued.
- **Only on local sites:** exports are refused unless the site runs on `localhost`, `127.0.0.1`, a `*.local`/`*.test`/`*.localhost` host or with `WP_ENVIRONMENT_TYPE` set to `local`.

## Requirements

WordPress 6.8 or newer, PHP 8.1 or newer, a single site (no multisite). The local site must be able to write to the export folder.

## Installation

1. Download `rmd-migrate-from-localdev.zip` from the latest [release](https://github.com/reicheltmediadesign/wordpress-rmd-migrate-from-localdev/releases). **Do not use the “Source code” downloads**, they lack the update library.
2. In your **local** WordPress go to Plugins → Add New → Upload Plugin, choose the zip and activate it.
3. Open **Tools → Migrate from localdev**.

The plugin is not copied into its own exports and is removed from the list of active plugins in the dump, so it never runs on the target.

## Usage

1. **Add a profile** per target with its site address, e.g. `https://example.com`, and ideally the server path of the WordPress folder on the target.
2. Click **Export**. The progress bar shows the current table or file count.
3. Open the export folder shown at the end. It contains:

   | Entry | Use |
   | --- | --- |
   | `files/` | The complete site. Upload its contents into the web root of the target (SFTP). |
   | `database.sql.gz` | Import in phpMyAdmin (Import tab) into the database of the target. Existing tables with the same names are replaced. |
   | `wp-config-template.php` | Only for a new installation: enter the database credentials of the target and upload it as `wp-config.php`. |
   | `MIGRATION.txt` | Next steps, statistics and remaining references to the local site. |

4. On the target: log in with your local users, save **Settings → Permalinks** once and clear caches.

`wp-config.php` is never part of `files/`, so an existing configuration on the target is not overwritten.

## Settings

### Profile

| Setting | Default | Description |
| --- | --- | --- |
| Name | – | E.g. "Development" or "Production". |
| Site address | – | Address of the site on the target. Replaces every spelling of the local address. |
| Server path | empty | Absolute path of the WordPress folder on the target server. Local file paths stored by plugins are replaced with it. Empty = paths stay and are listed in the report. |
| Table prefix | local prefix | Prefix of the tables in the dump. Must match `$table_prefix` on the target. |
| Environment type | Production | `WP_ENVIRONMENT_TYPE` in the `wp-config.php` template. |
| Search engines | Keep the setting of the local site | Sets "Discourage search engines from indexing this site" (Settings → Reading) in the dump: keep, discourage or allow indexing. Recommended for development and staging sites. |
| Copy files | on | Copy the complete WordPress folder. Off = database only. |
| Leave out | see below | Exclusion rules, one per line. |
| Tables without data | `actionscheduler_logs`, `yoast_indexable`, `yoast_indexable_hierarchy`, `yoast_seo_links` | Tables (without prefix) that are created empty. |
| GUIDs | on | Also replace the local address in post GUIDs. |
| Relative links | on | Adjust links like `/subfolder/wp-content/…` when the local site lives in a subfolder. |
| Revisions | off | Leave out post revisions. |
| Spam | on | Leave out spam and trashed comments. |
| Compatibility | on | Replace `utf8mb4_0900_*` and `utf8mb4_uca1400_*` collations with `utf8mb4_unicode_520_ci`. |
| Compression | on | Save the dump as `.sql.gz`. |

### Password protection

| Setting | Default | Description |
| --- | --- | --- |
| Protect the site | off | Visitors must enter a user name and password. Needs the server path (for `AuthUserFile`) and copied files. |
| User name | – | Without spaces and colons. |
| Password | – | Stored only as bcrypt hash; enter a new one to replace it. |

The export puts a block marked `# BEGIN RMD Migrate from Localdev: password protection` at the top of `files/.htaccess` and writes `files/.htpasswd` (Apache 2.4). `wp-cron.php` stays reachable so WordPress can run scheduled tasks. External services that call the site, such as payment webhooks, get "401 Unauthorized". Do not also use the directory protection of your hosting panel for the same folder: it writes into the same `.htaccess`, which every upload replaces.

### Exclusion rules

The syntax follows `.gitignore`: `name` matches a file or folder at any depth, `/name` only directly in the WordPress folder, a trailing `/` only folders, `*` and `?` stay within a path segment, `**` spans segments. Matching is case-insensitive. Defaults:

```
.git/
.github/
.svn/
node_modules/
.idea/
.vscode/
/*.md
/wp-content/cache/
/wp-content/upgrade/
/wp-content/upgrade-temp-backup/
/wp-content/ai1wm-backups/
/wp-content/updraft/
/wp-content/uploads/backwpup-*/
*.log
.DS_Store
Thumbs.db
desktop.ini
```

Always left out: `wp-config.php`, `.htaccess` (a version for the target is written instead), the export folder and this plugin.

### Export folder

Under **Settings** on the plugin page. Default is `wp-content/rmd-migrate-exports` (protected by a `.htaccess` deny rule); a folder outside the web root is better, e.g. `C:\Users\<name>\Exports`. Exports can be deleted on the plugin page.

## Developer hooks

- Filter `rmd_mfl_is_local_environment( bool $is_local, string $host )` – override the detection of local sites.
- REST (administrators on local sites only): `GET|POST|DELETE /wp-json/rmd-migrate-from-localdev/v1/export`, `POST …/export/step`.

## Privacy

The plugin makes no external requests, except for checking GitHub for plugin updates from the WordPress admin. Exports contain the complete database including user accounts and password hashes; keep them private and delete them when they are no longer needed.

## License

GPL-2.0-or-later. Uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
