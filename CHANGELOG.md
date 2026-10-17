# Changelog

All notable changes to WebCarrier BBS. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), version numbers follow [Semantic Versioning](https://semver.org/).

## [1.10.0] - 2026-10-17

### Added

- Doors can let callers cancel a line with Escape: `line()` takes a fourth parameter, the door then gets an empty input and `cancelled()` returns true, so an abort can be told apart from an empty line. Doors that do not ask for it see no change.
- Hi-Lo: Escape at the guess gives up the game (door version 1.3.0).

### Fixed

- Escape in a line of a door was swallowed by the terminal, so a door had no way to cancel an input.

## [1.9.0] - 2026-10-14

### Added

- Messages in the backend: every message opens in full with header and text as the terminal shows it, answers with a quote and the own signature, new messages, the original and the answers to a message, the previous and next message of an area. A search over subject, text and sender with filters for area and period works with the bulk actions.
- My mail: inbox and sent mail of the own account with reading, answering, writing to a handle and a search; private mail of other users stays hidden.
- Calls and chat in the backend: while a backend page is open the account is reachable, a caller who pages the sysop waits up to a minute, a bar on every page offers to start a chat or decline, and the tab shows the call. Chats start from a call, from the nodes on the overview or from the page of a user and look like the chat with the sysop in the terminal.
- Roles in the backend per level: none, moderator or full. Moderators read, answer and moderate messages, handle reports, files, polls, oneliners and waiting users and use the chat; the router refuses everything else and logs it.
- Reports: callers report messages with the key ! in the reader; a new backend page lists them with the reported message and lets the sysop delete or move the message, dismiss the report or write to the sender.
- Circular letters: a private message to all users or all users from a level, optionally only those who called in the last 90 days, sent in steps.
- Data export of a user as a ZIP with JSON and text for a request for access, without password hash, secrets of the second factor, the note of the sysop and reports about the user.
- The page of a user lists the last 50 calls with their length; the statistics show the average length of a call.
- The overview starts with what happened since the previous visit, with links to the filtered views.
- Head screen for menus: an ANSI or text screen above the automatic list, with or without the title bar. The menu page picks screens from a list, warns when head and list do not fit on one screen and shows a preview.

### Changed

- The log has filters for kind, handle, period and text, pages of 100 entries, addresses that can be bookmarked and a CSV export.
- The file list searches name, description and uploader with filters for area, state and period.
- Every change in the backend writes a log entry.
- Database version 9: roles of the levels, reachability and visits of backend accounts, the end of a call, head screens of menus, tables for calls to the sysop and reports.

### Fixed

- Calls that ended by a vanished line got no end; only the latest call of a user on that line is closed now.

## [1.8.1] - 2026-10-12

### Security

- The backup made before an update gets a random part in its file name, and the backup page reminds operators of servers other than Apache to deny web access to data/ and core/.
- The sysop menu in the terminal checks the level again with every input, so a sysop who loses the level during a call is out of the menu at once.
- The terminal page and the imprint and privacy pages send their security headers themselves and a Content Security Policy that only allows scripts and styles of the board.
- The handle of a caller is escaped in the notices a sysop gets when somebody pages him or registers.
- Adding files in the backend and the file import refuse the same dangerous file types as uploads of callers.
- The updater downloads a package only from the host the update information came from.
- Lines without a login are freed after three minutes without input; the keepalive of the terminal no longer holds them.
- For accounts with a second factor only wrong codes count for the login lock, wrong passwords no longer do, so such an account cannot be locked from outside. The overview warns a sysop about many failed password attempts on his account.

### Added

- SECURITY.md with the way to report security issues.

## [1.8.0] - 2026-10-11

### Added

- New since the last call: after the login a box counts new public messages, files, members, oneliners and door high scores since the previous call and the running polls the caller has not voted in, with keys for the new messages (N) and the new files (F). New macro @NEWSINCE@ for own logon screens and a switch in the settings (on by default). The hint for new polls moved into this box.
- Questionnaire for new users: up to 10 questions (free text, choice, yes or no; required or optional) asked during the registration, managed on a new backend page and off by default. The answers are stored with the account, shown on the user page and next to users waiting for validation, and keep the wording of the question.
- Automatic promotion: up to 5 rules on the levels page give callers a higher level at the login, by calls, days since the registration, public posts and approved uploads. Off by default, never down, never for locked or waiting users and sysops, with a preview of who would be promoted and a mark per user to leave him out.
- Second factor for logins: TOTP after RFC 6238 with any authenticator app, set up on the new backend page "My account" with a QR code drawn in the browser, ten one-time backup codes, and an emergency reset by the file data/2fa_reset.txt. Accounts with a second factor are asked for the code in the backend and in the terminal.
- Hint in the browser tab: while the tab with the terminal is in the background, its title and icon show a chat with the sysop, a new message, the turn in a door or a disconnect coming up. Switch in the settings, on by default.
- Callers are told in the terminal about private mail that arrives during the call, two minutes before their time for the day ends and one minute before the idle timeout.
- Open in WAnsiDraw: text and ANSI files in the viewer (key W) and screens and ANSI files in the backend open in the ANSI editor WAnsiDraw. The drawing travels in the part of the address after #, nothing is sent to a server. The address is a setting, empty switches the function off.
- Tube effect for the terminal with the levels off, light (scanlines) and strong (also glow, afterglow and a faint flicker), off by default, with a preview in the settings. Callers can switch it off for themselves (key R in their settings), reduced motion is respected.
- Every message gets a globally unique id made of the id of the board and a random part, and replies remember the id of the original. This prepares a later network between boards and changes nothing for callers. The id of the board is shown in the settings.

### Changed

- Wrong codes of the second factor count for the login lock like wrong passwords.
- Database version 8: tables for the questionnaire, the rules of the automatic promotion and the backup codes, new columns for users and messages, indexes on the time columns the new box counts by. Existing messages get their ids in steps of 500.

### Fixed

- On Windows the terminal looked for api.php under a wrong address when the board ran in the root of a host.

## [1.7.0] - 2026-10-10

### Added

- Meeting point (menu command TELECONF, key T in the main menu): a chat room for everybody online, with the lines of the last 30 minutes on entering, who is in the room, /W, /ME actions and system lines when someone enters or leaves (also on hanging up, disconnect or idle timeout). The last 50 lines are kept and can be deleted in the backend.
- Messages to a node from Who's online (key N), with a setting to refuse them, a flood limit and a log entry without the text.
- Profiles (menu command PROFILE, key P in the user list, key I in the message reader) with about me and the best places in the door high score lists. Callers write about me and a signature in the settings; the signature is added to new messages and left out when quoting. The sysop can empty both in the backend.
- Message search (menu command MSG_SEARCH, key F in the message menu) in subject, text and sender of public messages, case-insensitive also for umlauts, without moving the read pointer. The reader shows the original of a reply (O) and the replies to a message (T).
- File lists: view text and ANSI files up to 64 KB in the terminal (V), a list of up to 10 marked files (M, K) that downloads one after the other, and choosing files by their number in the last list.
- Polls (menu command POLLS, key V in the main menu) with a new backend page; a hint in the greeting when there are new polls.

### Changed

- File lists have fixed columns with headers above them, one line per file, long names on their own line, descriptions wrapped to at most four lines and no entry split by a page break. Sizes show K and M.
- The reader shows the rare keys behind ? help, so the command line stays below 80 characters.
- The statistics page starts with a band of key figures compared to the period before, followed by column charts of the same height: calls per day (7, 30 or 90 days), calls by time of day, new users per month, uploads and downloads per month. Sysops and users waiting for validation are left out of calls and active users, new users show how many of them are waiting. Counting is done in the database.
- Database version 7: tables for the meeting point and polls, new columns for profiles and node messages, indexes on calls and the log; new menu items for the meeting point, polls and the message search.

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

[1.10.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.8.1...v1.9.0
[1.8.1]: https://github.com/alph4r79/webcarrierbbs/compare/v1.8.0...v1.8.1
[1.8.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/alph4r79/webcarrierbbs/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/alph4r79/webcarrierbbs/releases/tag/v1.0.0
