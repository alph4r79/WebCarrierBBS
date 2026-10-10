# Changelog

All notable changes to WebCarrier BBS. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), version numbers follow [Semantic Versioning](https://semver.org/).

## [1.6.0] - 2026-10-09

### Added

- High score lists for doors: a door reports one value per caller with doorScore() and removes it with doorScoreClear(), every door keeps its own list, doors are never compared. The registration can say whether more ('high', default) or less ('low') is better; on a tie the earlier entry wins. Locked users and users waiting for validation are not listed.
- Menus with doors show a box with the leader of every door (up to 8 doors, latest entries first). The new menu command DOORTOP (key B in the doors menu) shows the top 3 of every door and the top 10 of one door with the own place. New macro @DOORTOP@ for own screens.
- Message of the sysop: up to three lines with colours, set on the overview in the backend with an optional last day, shown to every caller after the login. New macro @SYSOPMSG@.
- The doors page in the backend shows the number of entries and the top 3 of every door.

### Changed

- Hi-Lo reports the fewest tries of every player to its high score list (version 1.2.0).
- Reset scores on the doors page also clears the high score list of the door.
- The automatic update check runs every 12 hours instead of once a day. Check now does not move the next automatic check.
- Database version 6: new table door_scores, the doors menu gets the item High scores.

## [1.5.0] - 2026-10-08

### Added

- Ban list in the backend: banned handles (with * as wildcard) block new registrations, banned words are rejected in oneliners, subjects, messages, private mail and locations. A message with a banned word goes back into the editor with the text kept. Uploads with a banned word in the description wait for the sysop. Sysops are not affected, existing users on the list are shown in the backend.
- Own texts: every terminal text can be changed per language in the backend, with a preview in the terminal font and warnings for missing placeholders and overlong lines. Own texts are stored in the database and survive updates.
- Statistics page: calls per day, new users, uploads and downloads per month and top lists for files, writers and callers.
- Doors page: version and menu of every door, add a door to the doors menu or remove it with one click, reset scores, and a list of door files that could not be loaded with the reason. The overview shows a task when a door file is faulty.
- Doors can wait: Engine::wait() shows the output, pauses for up to five seconds and continues without a key, for example to show the move of the computer after the move of the caller. Keys during the pause are dropped, the automatic input does not count as activity.
- Door guide (docs/DOOR_GUIDE.md): how doors work, output, input, saved data, own texts in two languages, interruptions, a complete example door and a reference of the methods doors can use.

### Changed

- A faulty door file no longer breaks the board, it is skipped and listed in the backend. An error inside a running door takes the caller back to the menu and is logged.
- pause() inside a door returns to the door, hot() inside a door delivers upper case letters.
- Hi-Lo keeps its texts in its own file and serves as the template for new doors.
- Clearing the log asks again and says that the upload and download statistics are cleared as well.

## [1.4.0] - 2026-10-08

### Added

- Sysop menu in the terminal (key ! in the main menu, only for sysops): review new users, approve or delete waiting uploads, edit users, disconnect a node, broadcast and chat.
- Chat between sysop and caller, broadcast messages and paging that actually reaches a sysop who is online. The terminal polls for messages, every 10 seconds and every 1.5 seconds while chatting. Prompt and typed text are redrawn after an incoming message.
- Optional validation of new users: they can sign up, see the new screen pending and are disconnected until the sysop validates them in the terminal or in the backend. The sysop gets a notice when someone signs up.
- Backend: filter for users waiting for validation with single and bulk actions, a task on the overview, a Disconnect button for every node and a broadcast form.
- Sysops can move a public message to another area right from the message reader (key M).

### Changed

- The settings have a new section Registration with the options for new users.
- Default screens moved from install/defaults to core/defaults, so updates can bring new screens to existing boards.

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

[1.6.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/alph4r79/webcarrierbbs/releases/tag/v1.0.0
