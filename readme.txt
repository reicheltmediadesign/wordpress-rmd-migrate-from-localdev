=== RMD Migrate from Localdev ===
Contributors: reicheltmediadesign
Tags: migration, search replace, database export, localhost, deployment
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Prepares a local development site for upload: a database dump with serialization-safe search and replace and a copy of all files.

== Description ==

RMD Migrate from Localdev turns a local WordPress site into an upload-ready package for a staging or production server. You upload the result yourself, files via SFTP and the database via phpMyAdmin.

* Serialization-safe search and replace of every spelling of the local address (http/https, protocol-relative, JSON-escaped, URL-encoded, relative links) and of local file paths.
* The local database is never changed; replacements happen while writing the dump.
* Profiles for several targets, e.g. development and production.
* Complete copy of the site with exclusion rules; linked plugin repositories are exported from their release zip or with their .distignore.
* .htaccess and a wp-config.php template prepared for the target, optional table prefix change.
* Report with next steps and every value that still refers to the local site.
* Runs in short steps with a progress bar; only works on local development sites.

== Installation ==

1. Upload the plugin zip in your local WordPress under Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to Tools → Migrate from localdev, add a profile and click Export.

== Frequently Asked Questions ==

= Why not a simple SQL search and replace? =

Many settings are stored as serialized PHP data that contains the length of every string. Replacing an address with one of a different length breaks these values. The plugin rewrites them with correct lengths.

= Does the plugin upload anything? =

No. It writes files to a local folder; uploading is up to you.

== Changelog ==

= 0.2.0 =
* New: profile option "Search engines" to discourage or allow indexing on the target.
* New: password protection (HTTP basic authentication) for staging targets, renewed with every upload.

= 0.1.0 =
* Initial release.
