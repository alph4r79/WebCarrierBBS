#!/bin/bash
# WebCarrier BBS tests: run_all.sh runs everything in fresh throwaway instances under tests/run:
# migrations from older versions, Hi-Lo on old boards, and all suites on SQLite and (if available)
# MariaDB. Results in tests/run/res, a summary in tests/run/summary.txt. Exit code 1 if anything failed.
#
#   run_all.sh            everything
#   run_all.sh --no-browser   without the Playwright suites
#   run_all.sh --keep     leave the servers running afterwards (stop with serve.sh stop)
#
# Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
# SPDX-License-Identifier: AGPL-3.0-or-later
. "$(dirname "$0")/env.sh"
BROWSER=1
KEEP=0
for a in "$@"; do
  case "$a" in
    --no-browser) BROWSER=0 ;;
    --keep) KEEP=1 ;;
  esac
done
R=$RUN/res
SUMMARY=$RUN/summary.txt
FAIL=0
cd "$TESTS"

"$TESTS/serve.sh" stop >/dev/null
rm -rf "$RUN/inst" "$RUN/mdb" "$R" "$RUN"/shots_* "$RUN/arch"
mkdir -p "$R"
: > "$SUMMARY"

say() { echo "$*" | tee -a "$SUMMARY"; }
# result line of a suite: "== N passed, M failed"
result() {
  local name=$1 out=$2 line
  line=$(grep -E '^== [0-9]+ passed' "$out" | tail -1)
  [ -n "$line" ] || line='NO SUMMARY'
  echo "$line" | grep -q ', 0 failed' || FAIL=1
  say "$(printf '  %-22s %s' "$name" "$line")"
}

if [ $BROWSER = 1 ] && ! node -e "try{require(process.env.CB_PLAYWRIGHT||'playwright')}catch(e){try{require('playwright')}catch(e2){process.exit(1)}}" 2>/dev/null; then
  say "Playwright not found: browser suites skipped (npm install playwright, or set CB_PLAYWRIGHT)"
  BROWSER=0
fi
MY=0
if [ -n "$MARIADBD" ] && [ -n "$MARIADB" ]; then
  MY=1
  INSTALL_DB=""
  for x in mariadb-install-db mariadb-install-db.exe; do [ -x "$MARIADB_BIN/$x" ] && INSTALL_DB=$MARIADB_BIN/$x; done
  mkdir -p "$RUN/mdb"
  if ! "$INSTALL_DB" --datadir="$(win "$RUN/mdb/data")" --port="$MYSQL_PORT" --password="$MYSQL_PASS" > "$RUN/mdb/install.log" 2>&1; then
    say "MariaDB could not be set up (see run/mdb/install.log): MariaDB skipped"
    MY=0
    rm -rf "$RUN/mdb"
  fi
else
  say "MariaDB not found: MariaDB skipped (set CB_MARIADB_BIN)"
fi

# instances: fresh, old versions to migrate, old boards for Hi-Lo
"$TESTS/mk.sh" sq >/dev/null
"$TESTS/mk.sh" sqold "$REF_111" >/dev/null
"$TESTS/mk.sh" sq10 "$REF_110" >/dev/null
"$TESTS/mk.sh" o150 "$REF_15" >/dev/null
"$TESTS/mk.sh" o180 "$REF_18" >/dev/null
"$TESTS/mk.sh" o1110 "$REF_111" >/dev/null
if [ $MY = 1 ]; then
  "$TESTS/mk.sh" my >/dev/null
  "$TESTS/mk.sh" myold "$REF_111" >/dev/null
  "$TESTS/mk.sh" my10 "$REF_110" >/dev/null
fi
"$TESTS/serve.sh" start >/dev/null
[ $KEEP = 1 ] || trap '"$TESTS/serve.sh" stop >/dev/null' EXIT
python "$TESTS/mkarch.py" "$RUN/arch" >/dev/null

for n in sq sqold sq10 o150 o180 o1110; do
  $PHP install.php "http://127.0.0.1:$(port_of $n)/" > "$R/install_$n.out" 2>&1
done
if [ $MY = 1 ]; then
  mysql_cmd -e "CREATE DATABASE wc_my CHARACTER SET utf8mb4; CREATE DATABASE wc_myold CHARACTER SET utf8mb4; CREATE DATABASE wc_my10 CHARACTER SET utf8mb4;"
  for n in my myold my10; do
    $PHP install.php "http://127.0.0.1:$(port_of $n)/" mysql "wc_$n" "$MYSQL_PORT" > "$R/install_$n.out" 2>&1
  done
fi

say "== migrations"
for pair in "sqold sq" "myold my"; do
  set -- $pair
  [ -d "$RUN/inst/$1" ] || continue
  export CB_ROOT=$RUN/inst/$1 CB_BASE=http://127.0.0.1:$(port_of $1)/
  $PHP seed.php > "$R/mig_$1.out" 2>&1
  "$TESTS/sync.sh" "$1" >/dev/null
  curl -s -o /dev/null "$CB_BASE"
  $PHP schema.php "$RUN/inst/$2" > "$R/schema_$2.txt"
  $PHP schema.php "$RUN/inst/$1" > "$R/schema_$1.txt"
  if diff -q "$R/schema_$2.txt" "$R/schema_$1.txt" >/dev/null; then
    say "$(printf '  %-22s %s' "$1 (1.11.0)" "schema identical with a new installation")"
  else
    FAIL=1
    say "$(printf '  %-22s %s' "$1 (1.11.0)" "SCHEMA DIFFERS, see res/schema_*.txt")"
  fi
done
for pair in "sq10 sq" "my10 my"; do
  set -- $pair
  [ -d "$RUN/inst/$1" ] || continue
  export CB_ROOT=$RUN/inst/$1 CB_BASE=http://127.0.0.1:$(port_of $1)/
  $PHP seed_old.php > "$R/seed_old_$1.out" 2>&1
  result "$1 seed (1.10.0)" "$R/seed_old_$1.out"
  "$TESTS/sync.sh" "$1" >/dev/null
  CB_MIGRATED=1 $PHP check_mig.php "$R/mig_$1.json" > "$R/check_mig_$1.out" 2>&1
  result "$1 check_mig" "$R/check_mig_$1.out"
  $PHP schema.php "$RUN/inst/$1" > "$R/schema_$1.txt"
  if diff -q "$R/schema_$2.txt" "$R/schema_$1.txt" >/dev/null; then
    say "$(printf '  %-22s %s' "$1 (1.10.0)" "schema identical with a new installation")"
  else
    FAIL=1
    say "$(printf '  %-22s %s' "$1 (1.10.0)" "SCHEMA DIFFERS, see res/schema_*.txt")"
  fi
done

say "== Hi-Lo of today on old boards"
for n in o150 o180 o1110; do
  export CB_ROOT=$RUN/inst/$n CB_BASE=http://127.0.0.1:$(port_of $n)/
  cp "$ROOT/doors/hilo.php" "$CB_ROOT/doors/hilo.php"
  $PHP t_hilo_old.php > "$R/${n}_t_hilo_old.out" 2>&1
  result "$n t_hilo_old" "$R/${n}_t_hilo_old.out"
  if [ $n != o1110 ]; then
    $PHP t_hilo_old2.php > "$R/${n}_t_hilo_old2.out" 2>&1
    result "$n t_hilo_old2" "$R/${n}_t_hilo_old2.out"
  fi
done

suites() {
  local n=$1
  export CB_ROOT=$RUN/inst/$n CB_BASE=http://127.0.0.1:$(port_of $n)/ CB_LOG=$RUN/inst/$n.log CB_ARCH=$RUN/arch
  mkdir -p "$RUN/shots_$n"
  export SHOTS=$(win "$RUN/shots_$n") CB_SHOTS=$(win "$RUN/shots_$n") PHPINI=$(win "$PHPINI") TCPATH=$(win "$TESTS/tc.php")
  cp waittest.php "$CB_ROOT/doors/"
  $PHP seed.php >/dev/null
  $PHP seed_long.php >/dev/null
  for s in t_adname t_backend t_regress t_f5 t_idle t_up t_hilo t_dw t_board; do
    $PHP -r 'require "tc.php"; q("DELETE FROM {nodes}");'
    $PHP $s.php > "$R/${n}_$s.out" 2>&1
    result "$n $s" "$R/${n}_$s.out"
  done
  [ $BROWSER = 1 ] || return 0
  export CB_MSG=$($PHP -r 'require "tc.php"; echo val("SELECT id FROM {messages} WHERE subject=?", ["Hallo zusammen"]);')
  export CB_PIPE=$($PHP -r 'require "tc.php"; echo val("SELECT id FROM {messages} WHERE subject LIKE ?", ["Farben%"]);')
  for s in pw_f5 pw_netfail pw_tele pw_dw pw_shots pw_board; do
    $PHP prep.php >/dev/null
    $PHP -r 'require "tc.php"; q("UPDATE {msg_areas} SET public=CASE WHEN read_level<=10 THEN 1 ELSE 0 END"); setting("bbs_url", "");'
    rm -f "$CB_ROOT/data/tmp/rate/"*
    timeout 900 node $s.js > "$R/${n}_$s.out" 2>&1
    result "$n $s" "$R/${n}_$s.out"
  done
}

say "== all suites on SQLite"
suites sq
if [ $MY = 1 ]; then
  say "== all suites on MariaDB"
  suites my
fi
for n in sq my; do
  [ -f "$RUN/inst/$n.log" ] || continue
  if grep -q -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$RUN/inst/$n.log"; then
    FAIL=1
    say "  PHP warnings in the server log of $n"
  fi
done
say "== $([ $FAIL = 0 ] && echo 'all passed' || echo 'FAILURES, see tests/run/res')"
exit $FAIL
