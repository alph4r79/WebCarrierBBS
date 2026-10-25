# WebCarrier BBS tests

End-to-end tests of the board. They install throwaway copies of the board, talk to them over HTTP like the browser terminal and the backend do, and drive real browsers with Playwright. Nothing touches your own installation. Everything a run creates (instances, databases, logs, results, screenshots) lands in `tests/run/`, which git ignores. This folder is not part of release archives (`export-ignore` in `.gitattributes`).

## Requirements

- **Bash** (on Windows: Git Bash) with `git`, `curl` and `python` (3.x).
- **PHP 8.1 or newer** with pdo_sqlite, mbstring, curl, zip, sodium and fileinfo. The scripts write their own `tests/run/php.ini` that loads these extensions from the `ext` folder next to `php`; set `CB_PHP_EXT` if it is somewhere else.
- **MariaDB** (optional) for the MySQL runs. The scripts look for `mariadbd` in `PATH` and under `C:\Program Files\MariaDB *\bin`, or use `CB_MARIADB_BIN`. A run sets up its own server with a fresh data folder in `tests/run/mdb` on port 3399, root password `wegwerf`. Without MariaDB only SQLite is tested.
- **Node.js with Playwright** (optional) for the browser suites (`pw_*.js`), Chromium is used:

  ```
  cd tests
  npm install --no-save playwright
  npx playwright install chromium
  ```

  If Playwright is installed elsewhere, set `CB_PLAYWRIGHT` to its folder. Without it the browser suites are skipped.

## A complete run

```
bash tests/run_all.sh
```

This takes about 15 minutes. It

1. copies the working tree into fresh instances under `tests/run/inst`, plus older versions from git for the migration tests (1.11.0, 1.10.0, and 1.8.0 and 1.5.0 for Hi-Lo on old boards),
2. starts one PHP server per instance on ports 8811 to 8819 and the throwaway MariaDB,
3. installs every instance through the web installer (sysop `Sysop`, password `geheim123`, only in these instances),
4. updates the old versions to the working tree and compares their schema with a new installation,
5. runs all suites on SQLite and on MariaDB,
6. stops the servers and writes `tests/run/summary.txt`.

The exit code is 0 only if everything passed. The output of every suite is in `tests/run/res/`, screenshots of the browser suites in `tests/run/shots_sq` and `tests/run/shots_my`.

Options: `--no-browser` skips the Playwright suites, `--keep` leaves the servers running for a closer look (`bash tests/serve.sh stop` ends them). Delete `tests/run/` to start from scratch.

## Single suites

With the servers running (`run_all.sh --keep` or `serve.sh start` after a run), a suite runs on its own:

```
cd tests
export CB_ROOT=$PWD/run/inst/sq CB_BASE=http://127.0.0.1:8811/ CB_LOG=$PWD/run/inst/sq.log CB_ARCH=$PWD/run/arch
php -c run/php.ini t_dw.php
CB_MSG=2 node pw_dw.js
```

## Files

| File | Purpose |
|---|---|
| `env.sh` | Shared settings, ports, PHP and MariaDB detection |
| `run_all.sh` | The complete run |
| `serve.sh` | Starts and stops the test servers |
| `mk.sh`, `sync.sh` | Copy the working tree (or a git ref) into an instance, update an instance |
| `install.php` | Runs the web installer of an instance |
| `tc.php`, `pwlib.js` | Helpers: terminal client, database access, checks; the same for Playwright |
| `seed.php`, `seed_long.php`, `seed_old.php`, `prep.php` | Test data, a long message, data of a 1.10.0 board, test door in the menu |
| `waittest.php`, `mkarch.py` | Test door with wait() and Escape, test archives for the upload check |
| `schema.php`, `check_mig.php` | Schema of an instance for comparing, checks after an update from 1.10.0 |
| `t_*.php` | Suites over HTTP: backend, regression of all commands and pages, reload (F5), idle time, uploads, Hi-Lo, direct dial links, public boards, BBS ad |
| `pw_*.js` | Browser suites: F5, line error, meeting point, direct dial, public boards, screenshots |
| `checks.sh`, `check_i18n.php` | Project checks: lint, dashes, line endings, translations, line width |
