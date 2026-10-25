#!/bin/bash
# WebCarrier BBS tests: mk.sh NAME [REF] copies the working tree (or git REF) of the project to
# tests/run/inst/NAME, without config, data and the tests themselves.
#
# Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
# SPDX-License-Identifier: AGPL-3.0-or-later
set -e
. "$(dirname "$0")/env.sh"
DEST="$RUN/inst/$1"
REF="$2"
rm -rf "$DEST"
mkdir -p "$DEST"
cd "$ROOT"
if [ -n "$REF" ]; then
  git archive "$REF" | tar -x -C "$DEST"
else
  git ls-files -co --exclude-standard -z | while IFS= read -r -d '' f; do
    case "$f" in tests/*) continue ;; esac
    if [ -f "$f" ]; then
      mkdir -p "$DEST/$(dirname "$f")"
      cp "$f" "$DEST/$f"
    fi
  done
fi
rm -rf "$DEST/tests" "$DEST/core/config.php"
mkdir -p "$DEST/data/tmp" "$DEST/data/files" "$DEST/data/screens"
echo "instance $1 ready"
