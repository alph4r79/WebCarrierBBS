# WebCarrier BBS

A real mailbox from the nineties, running on ordinary PHP webspace.

Callers open your domain and land in an 80×25 DOS screen with the IBM VGA font, ANSI colours and a dial tone. From there on everything works like a BBS in 1994: log in or register, read and write messages, send private mail, browse file areas, download and upload, play doors, leave a oneliner. No mouse, keyboard only.

For the sysop there is a modern web backend: settings, users, levels, message and file areas with bulk actions, menu editor, ANSI screen editor with live preview, approval of uploads, a bulk import for whole shareware CDs and backups as ZIP download.

Installing it works like WordPress: upload the folder, open `/install/`, fill in a form, done.

## Features

- Browser terminal: 80×25 text mode on a canvas, original IBM VGA 9×16 font, CP437, ANSI.SYS escape codes, blinking text, emulated modem speed (300 to 57600 bps), dial tones and handshake
- Nodes with "All lines are busy", who's online, last callers, idle timeout, daily time limits
- Message areas with read and write levels, new scan, replies with quoting, line editor (`/S`, `/A`, `/L`), private mail
- File areas with listing, new files, search, download, upload with FILE_ID.DIZ, daily download limits and upload/download ratio
- Oneliners, user list, personal statistics and settings, expert mode, page sysop
- Sysop menu in the terminal: chat with callers, broadcast, disconnect a node, review new users and uploads; optional validation of new users
- Menu system configurable in the backend (26 commands), ANSI or pipe code screens with macros
- Doors as small PHP classes, example door included
- Optional update check and one-click updates from the backend, with signed packages, a backup before every update and automatic rollback on errors
- German and English, SQLite (zero configuration) or MySQL/MariaDB

## Requirements

PHP 8.1 or newer with PDO (SQLite or MySQL) and mbstring. ZipArchive is optional and reads FILE_ID.DIZ. Apache with `.htaccess` works out of the box, for nginx see the sysop guide.

## Documentation

- [Sysop Guide](docs/SYSOP_GUIDE.md) (German)
- [User Guide](docs/USER_GUIDE.md) (German)
- [Changelog](CHANGELOG.md)

## Deutsch

WebCarrier BBS ist eine Mailbox wie in den Neunzigern, die auf normalem PHP-Webspace läuft. Hochladen, `/install/` aufrufen, Formular ausfüllen, fertig. Anrufer bedienen die Box komplett per Tastatur in echter DOS-Optik, der Sysop verwaltet alles bequem im Web-Backend. Die Anleitungen liegen in `docs/`.

## License

Copyright (C) 2026 [Christoph Scheel](https://chrisscheel.de)

WebCarrier BBS is free software under the [GNU Affero General Public License v3.0 or later](LICENSE). If you run a modified version on a public server, the AGPL requires you to offer your changed source code to the callers. The terminal page and the backend link to the source, set `CB_SOURCE_URL` in `core/bootstrap.php` to your own repository.

The bundled VGA font is a dump of the IBM VGA ROM font and not covered by the AGPL, see [NOTICE](NOTICE).
