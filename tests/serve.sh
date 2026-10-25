#!/bin/bash
# WebCarrier BBS tests: serve.sh start|stop starts or stops the throwaway MariaDB (if there is one and
# tests/run/mdb exists) and one PHP server per instance in tests/run/inst.
#
# Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
# SPDX-License-Identifier: AGPL-3.0-or-later
. "$(dirname "$0")/env.sh"
PIDS=$RUN/pids

stop() {
  [ -f "$PIDS" ] || return 0
  while read -r pid; do
    kill "$pid" 2>/dev/null
  done < "$PIDS"
  rm -f "$PIDS"
  # PHP and MariaDB on Windows do not always end with the shell job, free the ports as well
  if command -v powershell >/dev/null 2>&1; then
    local ports="$MYSQL_PORT"
    for spec in $INSTANCES; do ports="$ports,$((PORT_BASE + ${spec#*:}))"; done
    powershell -NoProfile -Command "foreach (\$p in $ports) { Get-NetTCPConnection -LocalPort \$p -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id \$_.OwningProcess -Force -ErrorAction SilentlyContinue } }" 2>/dev/null
  fi
  sleep 1
}

start() {
  stop
  if [ -n "$MARIADBD" ] && [ -d "$RUN/mdb/data" ]; then
    "$MARIADBD" --datadir="$(win "$RUN/mdb/data")" --port="$MYSQL_PORT" --bind-address=127.0.0.1 --console > "$RUN/mdb/server.log" 2>&1 &
    echo $! >> "$PIDS"
  fi
  for spec in $INSTANCES; do
    n=${spec%%:*}
    [ -d "$RUN/inst/$n" ] || continue
    (cd "$RUN/inst/$n" && exec php -c "$PHPINI" -S 127.0.0.1:$((PORT_BASE + ${spec#*:})) > "$RUN/inst/$n.log" 2>&1) &
    echo $! >> "$PIDS"
  done
  sleep 1
  if [ -n "$MARIADB" ] && [ -d "$RUN/mdb/data" ]; then
    for i in $(seq 1 20); do mysql_cmd -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
  fi
}

case "$1" in
  start) start; echo "servers started" ;;
  stop) stop; echo "servers stopped" ;;
  *) echo "usage: serve.sh start|stop"; exit 2 ;;
esac
