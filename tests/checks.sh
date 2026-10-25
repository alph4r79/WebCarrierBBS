#!/bin/bash
# WebCarrier BBS tests: project checks over the changed and new files and the whole repository:
# php -l, node --check, dashes U+2013/U+2014, CRLF (not *.ans), translations, lines over 79 characters.
#
# Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
# SPDX-License-Identifier: AGPL-3.0-or-later
. "$(dirname "$0")/env.sh"
cd "$ROOT"
INI=$PHPINI
FILES=$( (git diff --name-only; git ls-files -o --exclude-standard) | sort -u)
echo "changed/new: $(echo $FILES | wc -w) files"
fail=0
for f in $FILES; do
  case "$f" in
    *.php) php -c "$INI" -l "$f" >/dev/null || { echo "LINT FAIL $f"; fail=1; } ;;
    *.js) node --check "$f" || { echo "NODE FAIL $f"; fail=1; } ;;
  esac
done
echo "php -l / node --check done"
# all php files of the project once more
for f in $(git ls-files '*.php') core/upcheck.php; do php -c "$INI" -l "$f" >/dev/null || { echo "LINT FAIL $f"; fail=1; }; done
echo "php -l all done"
# dashes U+2013 / U+2014 and CRLF in changed files (not *.ans)
for f in $FILES; do
  case "$f" in *.ans) continue ;; esac
  if grep -q $'\xe2\x80\x93\|\xe2\x80\x94' "$f"; then echo "DASH in $f"; grep -n $'\xe2\x80\x93\|\xe2\x80\x94' "$f" | head -3; fail=1; fi
  if grep -q $'\r' "$f"; then echo "CRLF in $f"; fail=1; fi
done
echo "dash/CRLF check done"
# whole repo: dashes and CRLF outside *.ans
for f in $(git ls-files | grep -v '\.ans$' | grep -v -E '\.(png|jpg|gif|ico|woff2?|zip|sqlite)$') core/upcheck.php; do
  if grep -q $'\xe2\x80\x93\|\xe2\x80\x94' "$f"; then echo "REPO DASH in $f"; fail=1; fi
  if grep -q $'\r' "$f"; then echo "REPO CRLF in $f"; fail=1; fi
done
echo "repo dash/CRLF check done"
# umlaut substitutes in German texts (rough check of new German strings)
php -c "$INI" "$TESTS/check_i18n.php" . || fail=1
php -c "$INI" -r '
$de = require "lang/de.php"; $en = require "lang/en.php";
$d = array_diff_key($de, $en) + array_diff_key($en, $de);
if ($d) { echo "LANG KEYS DIFFER: ", implode(", ", array_keys($d)), "\n"; exit(1); }
echo "lang keys equal (", count($de), ")\n";
$bad = 0;
foreach (["de" => $de, "en" => $en] as $l => $a) {
  foreach ($a as $k => $v) {
    if (!is_string($v)) continue;
    foreach (explode("|CR", $v) as $line) {
      $vis = str_replace("||", "|", preg_replace("/\\|(\\d\\d|CL)/", "", $line));
      $vis = preg_replace("/\\{\\d\\}/", "", $vis);
      if (mb_strlen($vis) > 79) { echo "LONG $l $k: ", mb_strlen($vis), "\n"; $bad = 1; }
    }
  }
}
exit($bad);
' || fail=1
echo "== checks done, fail=$fail"
