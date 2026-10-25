# WebCarrier BBS tests: shared settings, sourced by the other scripts.
# Everything a run creates (instances, databases, logs, results, screenshots) goes to tests/run/.
#
# Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Environment variables (all optional):
#   CB_RUN          folder for instances and output, default tests/run
#   CB_PORT_BASE    first port of the PHP test servers, default 8811 (nine ports from there)
#   CB_MARIADB_BIN  folder with mariadbd and mariadb, found automatically if not set
#   CB_MYSQL_PORT   port of the throwaway MariaDB, default 3399
#   CB_MYSQL_PASS   root password of the throwaway MariaDB, default wegwerf
#   CB_PHP_EXT      extension folder of PHP, default the ext folder next to php
#   CB_PLAYWRIGHT   folder of the playwright module, if require('playwright') does not find it
#   CB_REF_111 / CB_REF_110 / CB_REF_18 / CB_REF_15   git refs of the old versions for the migration tests

TESTS=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$TESTS/.." && pwd)
RUN=${CB_RUN:-$TESTS/run}
PORT_BASE=${CB_PORT_BASE:-8811}
MYSQL_PORT=${CB_MYSQL_PORT:-3399}
MYSQL_PASS=${CB_MYSQL_PASS:-wegwerf}
REF_111=${CB_REF_111:-cd0e627}
REF_110=${CB_REF_110:-b726af5}
REF_18=${CB_REF_18:-bedba2e}
REF_15=${CB_REF_15:-000ced6}
export CB_MYSQL_PORT=$MYSQL_PORT CB_MYSQL_PASS=$MYSQL_PASS

# instance name and its offset from PORT_BASE
INSTANCES="sq:0 sqold:1 my:2 myold:3 sq10:4 my10:5 o150:6 o180:7 o1110:8"

port_of() {
  local spec
  for spec in $INSTANCES; do
    if [ "${spec%%:*}" = "$1" ]; then echo $((PORT_BASE + ${spec#*:})); return; fi
  done
}

# native path for Windows programs, unchanged elsewhere
win() {
  if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi
}

mkdir -p "$RUN"

# php.ini with the extensions the board and the tests need
PHPINI=$RUN/php.ini
if [ ! -f "$PHPINI" ]; then
  EXT=${CB_PHP_EXT:-$(dirname "$(command -v php)")/ext}
  {
    [ -d "$EXT" ] && echo "extension_dir=\"$(win "$EXT")\""
    for e in mbstring pdo_sqlite sqlite3 pdo_mysql curl openssl sodium zip fileinfo; do echo "extension=$e"; done
    printf 'memory_limit=512M\nerror_reporting=E_ALL\ndisplay_errors=On\nlog_errors=On\nupload_max_filesize=20M\npost_max_size=24M\n'
  } > "$PHPINI"
fi
PHP="php -c $PHPINI"

# MariaDB: CB_MARIADB_BIN, a mariadbd in PATH or the newest MariaDB under Program Files
MARIADB_BIN=${CB_MARIADB_BIN:-}
if [ -z "$MARIADB_BIN" ]; then
  if command -v mariadbd >/dev/null 2>&1; then
    MARIADB_BIN=$(dirname "$(command -v mariadbd)")
  else
    for d in "/c/Program Files/MariaDB "*/bin; do [ -x "$d/mariadbd.exe" ] && MARIADB_BIN=$d; done
  fi
fi
MARIADBD=""
MARIADB=""
if [ -n "$MARIADB_BIN" ]; then
  for x in mariadbd mariadbd.exe; do [ -x "$MARIADB_BIN/$x" ] && MARIADBD=$MARIADB_BIN/$x; done
  for x in mariadb mariadb.exe; do [ -x "$MARIADB_BIN/$x" ] && MARIADB=$MARIADB_BIN/$x; done
fi

mysql_cmd() {
  "$MARIADB" -h127.0.0.1 -P"$MYSQL_PORT" -uroot -p"$MYSQL_PASS" "$@"
}
