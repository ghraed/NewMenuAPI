#!/usr/bin/env bash
set -euo pipefail

FRONTEND_DIR="${FRONTEND_DIR:-/path/to/Menu_React}"
DOCROOT="${DOCROOT:-/var/www/menu_frontend}"

if [ ! -d "$FRONTEND_DIR" ]; then
  echo "FRONTEND_DIR not found: $FRONTEND_DIR" >&2
  exit 1
fi

cd "$FRONTEND_DIR"

if [ -f package-lock.json ]; then
  npm ci
else
  npm install
fi

npm run build

mkdir -p "$DOCROOT"
rm -rf "$DOCROOT"/*
cp -R "$FRONTEND_DIR/dist/." "$DOCROOT/"

echo "Frontend deployed to $DOCROOT"
