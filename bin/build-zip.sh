#!/usr/bin/env bash
#
# Builds dist/coldstash.zip for WordPress.org.
# What is left out is defined once, in .distignore.

set -euo pipefail

PLUGIN=coldstash
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"

rm -rf "$DIST"
mkdir -p "$DIST/$PLUGIN"

rsync -a --exclude-from="$ROOT/.distignore" --exclude='/dist' --exclude='/vendor' --exclude='.DS_Store' --exclude='/.idea' \
	"$ROOT/" "$DIST/$PLUGIN/"

( cd "$DIST" && zip -qr "$PLUGIN.zip" "$PLUGIN" )

echo "Built $DIST/$PLUGIN.zip"
unzip -l "$DIST/$PLUGIN.zip"
