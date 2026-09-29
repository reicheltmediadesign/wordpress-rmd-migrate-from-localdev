# RMD Migrate from Localdev – Projektregeln

WordPress-Plugin von reichelt media.design, läuft nur auf lokalen Entwicklungsseiten (XAMPP) und bereitet den Upload auf Entwicklungs- oder Produktivserver vor. Repo: `github.com/reicheltmediadesign/wordpress-rmd-migrate-from-localdev`, Lizenz GPL-2.0-or-later, Autor Philipp Reichelt. Aufbau und Release wie `rmd-opening-hours`, aber ohne npm/Build-Schritt.

## Aufbau

- `rmd-migrate-from-localdev.php` Bootstrap (muss PHP-7-parsebar bleiben), `includes/` PSR-4 `RMD\MigrateFromLocaldev\`.
- `includes/Domain/` ist **WordPress-frei** und wird mit plain PHPUnit getestet: `SerializedReplacer` (Parser statt `unserialize()`, Längen neu berechnen, Keys unverändert), `ReplacementPlan` (alle Schreibweisen von URL und Pfad; ein Durchlauf per `strtr`, längster Treffer zuerst, keine Ketten), `PathFilter` (.gitignore-Teilmenge), `Sql`, `Profile` (einzige Stelle für die Datenform eines Profils), `Htaccess`, `WpConfigTemplate`, `LocalEnvironment`.
- `includes/Export/`: `Job` (Zustand in Option `rmd_mfl_job`, Phasen database → scan → copy → finalize → done, je Request ~12 s), `DatabaseExport`, `FileExport`, `Finisher`, `Report`, `Exports` (Exportordner). Ausgabedateien werden beim Fortsetzen auf die im Zustand gespeicherte Länge gekürzt (`Exports::open_append`), damit abgebrochene Schritte nichts doppelt schreiben.
- `Rest\ExportController` treibt den Job an, `Admin\Page` (Werkzeuge → Migrate from localdev) mit `assets/js/admin.js` (Vanilla, `wp-api-fetch`).
- Zugriff: `manage_options` **und** `Plugin::is_local()`; jede Schreibaktion mit Nonce.
- Nie im Export: `wp-config.php`, `.htaccess` (wird neu geschrieben), Exportordner, dieses Plugin; im Dump fehlen Transients, `rmd_mfl_*`-Optionen, und das Plugin wird aus `active_plugins` entfernt.

## Code-Regeln

- PHP 8.1+, WP 6.8+. WordPress-Coding-Standards (`phpcs.xml.dist`), Methoden snake_case, Präfix `rmd_mfl_` / `RMD_MFL_` / Namespace `RMD\MigrateFromLocaldev`, Textdomain `rmd-migrate-from-localdev`.
- Quellstrings Englisch, Übersetzung in `languages/rmd-migrate-from-localdev-de_DE.po` von Hand nachziehen (`bash bin/make-i18n.sh` braucht WP-CLI; lokal nicht installiert, im Release-Workflow vorhanden).
- Neue Schreibweisen oder Sonderfälle beim Ersetzen immer mit Tests in `tests/Unit/ReplacementPlanTest.php` bzw. `SerializedReplacerTest.php`.
- Version an drei Stellen: Plugin-Header, `RMD_MFL_VERSION`, `readme.txt` (Stable tag). `bash bin/check-version.sh` prüft das.

## Build und Prüfung

```powershell
composer phpcs         # PHPCS
composer test          # PHPUnit (Domain)
bash bin/check-version.sh
bash bin/build-zip.sh  # dist/rmd-migrate-from-localdev.zip (Git Bash: zip, composer nötig)
```

Lokal testen: `.\bin\link-local.ps1 -WordPressPath "C:\xampp\htdocs\<seite>"` legt eine Junction in `wp-content\plugins` an. Das Plugin schließt sich selbst aus dem Export aus, auch als Junction. Für die Nutzung auf Kundenseiten (z. B. marleninflow.de) stattdessen das Release-Zip installieren.

## Git und Release

- Committen nur auf Anfrage, auf Feature-Branches; nie direkt auf `main`.
- Release: Versionen an allen Stellen erhöhen, `CHANGELOG.md` und `readme.txt` (Changelog) ergänzen, committen, Tag `vX.Y.Z` pushen. Der Workflow lintet, testet, baut Übersetzungen, erzeugt `rmd-migrate-from-localdev.zip` und `update.json` und legt ein **Draft-Release** an. Beide Assets müssen am Release bleiben (Update-Check liest `releases/latest/download/update.json`).
- Den vom Workflow angelegten Entwurf bearbeiten (Releases → Entwurf → Stift), nicht neu anlegen.
- Bei jedem Release Titel `vX.Y.Z – <Kernänderung>` und Release Notes (Englisch, Markdown in Codeblock) mit ausgeben; Grundlage `git log <letzter Tag>..HEAD`.
- `gh` ist lokal nicht installiert: Workflow-Status über `https://api.github.com/repos/reicheltmediadesign/wordpress-rmd-migrate-from-localdev/actions/runs`, Releases über `.../releases`.
- Topics fürs Repo: `wordpress`, `wordpress-plugin`, `migration`, `search-replace`, `localhost`.

## README

Nutzerorientiert (Features, Usage, Settings, Privacy, License). Kein Development-Abschnitt in der README; Build/Release steht hier.
