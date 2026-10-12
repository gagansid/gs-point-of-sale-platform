#!/usr/bin/env bash
# Membuat paket rilis zip untuk installer web (ADR 0016): kode dari commit (git archive), vendor tanpa dev,
# aset sudah di-build, tanpa .env/test/dokumen pengembangan. Hosting cukup unggah, ekstrak, buka /install.
#
#   scripts/release/build-package.sh <versi> [edisi=self_hosted] [folder-output=dist]
#   contoh: scripts/release/build-package.sh v1.2.0 self_hosted dist
set -euo pipefail

VERSION="${1:?Pemakaian: build-package.sh <versi> [edisi] [folder-output]}"
EDITION="${2:-self_hosted}"
OUT="${3:-dist}"

case "$EDITION" in
    saas|self_hosted) ;;
    *) echo "Edisi harus saas atau self_hosted" >&2; exit 1 ;;
esac

ROOT="$(git rev-parse --show-toplevel)"
mkdir -p "$OUT"
OUT_ABS="$(cd "$OUT" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PKG="$WORK/gs-point-of-sale-platform"
mkdir -p "$PKG"

# Hanya file yang ter-commit (tanpa .env, database lokal, docs/prompts, dll.)
git -C "$ROOT" archive HEAD | tar -x -C "$PKG"
cd "$PKG"

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

# File pengembangan tidak ikut paket
rm -rf tests docs .github .claude scripts AGENTS.md CLAUDE.md phpunit.xml phpstan.neon pint.json .editorconfig \
    package.json package-lock.json vite.config.js bootstrap/cache/*.php

# Edisi & versi bawaan paket (bisa diganti di form instal)
sed -i.bak -e "s/^POS_EDITION=.*/POS_EDITION=${EDITION}/" -e "s/^APP_VERSION=.*/APP_VERSION=${VERSION#v}/" .env.example
rm -f .env.example.bak
cp "$ROOT/docs/install.md" INSTALL.md

ZIP="$OUT_ABS/gspos-${VERSION}-${EDITION}.zip"
rm -f "$ZIP"
(cd "$WORK" && zip -qr "$ZIP" gs-point-of-sale-platform)
echo "$ZIP"
