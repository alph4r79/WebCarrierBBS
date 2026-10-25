#!/bin/bash
# WebCarrier BBS tests: sync.sh NAME copies the current working tree over the installed instance
# tests/run/inst/NAME (an update), config and data stay.
#
# Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
# SPDX-License-Identifier: AGPL-3.0-or-later
set -e
. "$(dirname "$0")/env.sh"
DEST="$RUN/inst/$1"
cd "$ROOT"
git ls-files -co --exclude-standard -z | while IFS= read -r -d '' f; do
  case "$f" in
    data/*|core/config.php|install/*|tests/*) continue ;;
  esac
  if [ -f "$f" ]; then
    mkdir -p "$DEST/$(dirname "$f")"
    cp "$f" "$DEST/$f"
  fi
done
echo "synced $1"
