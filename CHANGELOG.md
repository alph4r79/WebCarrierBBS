# Changelog

All notable changes to WebCarrier BBS. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), version numbers follow [Semantic Versioning](https://semver.org/).

## [1.3.0] - 2026-10-07

### Added

- Update check: once a day the backend can look at webcarrier-bbs.de for a new version. It is switched off by default and sends nothing but the request itself.
- Automatic update from the backend: backup first, maintenance mode for callers, download, check of size, SHA-256 and signature, copy with a saved copy of every replaced file. Any error restores the old state. Database changes are applied right after the new files are in place.
- When an update can only be done by hand (newer PHP needed, Git checkout, missing extensions, files not writable), the backend says why and links to the instructions.
- Callers see a short notice while the board is being updated.
- Backups made before an update are kept in data/backups, the last three are kept.

### Changed

- The overview in the backend starts with a list of notices (security, tasks, updates, hints, project news), followed by nodes, figures, latest events and a system box. Hints and news can be hidden.
- The figures are a compact list instead of six tiles, the table of latest events has proper columns and no longer scrolls sideways on phones.
- Explanations of switches in the settings are shown now.

## [1.2.0] - 2026-10-07

### Added

- Backend: create users directly on the user page, with the same handle rules as the registration in the terminal.
- Backend: checkboxes and bulk actions in the file list of an area: approve, delete and move to another area. Files are moved on disk, names that already exist in the target area are skipped and reported.
- Backend: rename files together with their description. Names are cleaned like uploads, duplicates and dangerous extensions are refused.
- Backend: checkboxes and bulk actions in the message list (delete, move to another area), moving also from the single message view.
- Backend: new page Backup that downloads a ZIP with core/config.php, the screens, the database (consistent SQLite copy or SQL dump for MySQL) and optionally the file areas.

### Changed

- Levels can only be assigned up to the own level, users with a higher level than the own can no longer be edited in the backend.
- Renaming a user in the backend refuses the reserved names (NEW, Sysop, All and their translations), like the registration does.
- File names are cleaned per character, an umlaut becomes one underscore instead of two.

### Fixed

- Deleting a file in the backend no longer removes the database entry if the file could not be deleted from disk.

## [1.1.0] - 2026-10-06

### Added

- Database migrations: schema changes are applied automatically on the first request after an update. The current state is stored in the setting `db_version`, an aborted update step is repeated on the next request.
- Installer in German and English with a language switch. Without a choice it follows the browser language. The installer language is preselected as the language of the BBS.

### Changed

- Three German terminal texts shortened so they fit into 79 columns.
- Release archives no longer contain `.gitattributes`.

## [1.0.0] - 2026-10-06

First public release.

- Browser terminal with 80x25 text mode, IBM VGA font, CP437, ANSI colours, emulated modem speed and dial sounds
- Login and registration, nodes, who's online, last callers, idle timeout, daily time limits
- Message areas with read and write levels, new scan, replies with quoting, line editor, private mail
- File areas with listing, search, download, upload with FILE_ID.DIZ, daily download limits and ratio
- Oneliners, user list, statistics, personal settings, expert mode, page sysop
- Menus configurable in the backend, ANSI and pipe code screens with macros
- Doors as PHP classes, example door Hi-Lo
- Sysop backend for settings, users, levels, areas, messages, files, bulk import, menus, screens and log
- Web installer for SQLite or MySQL/MariaDB
- German and English

[1.3.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/alph4r79/webcarrierbbs/releases/tag/v1.0.0
