#!/usr/bin/env bash
# Memeriksa paket zip sebelum dibagikan/diunggah (GSPOS-SEC-025): tidak boleh membawa rahasia atau data lokal.
# APP_KEY di .env yang ikut zip = siapa pun pemegang zip bisa memalsukan cookie/session/snapshot Livewire.
# Dipakai build-package.sh; jalankan juga untuk paket hosting manual (dist/hosting).
#
#   scripts/release/check-package.sh <file.zip>
set -euo pipefail

ZIP="${1:?Pemakaian: check-package.sh <file.zip>}"
[ -f "$ZIP" ] || { echo "File tidak ditemukan: $ZIP" >&2; exit 1; }

# .env (bukan .env.example), database & dump lokal, log, status/kode installer, session, kunci privat
FORBIDDEN='(^|/)\.env$|(^|/)\.env\.(local|production|backup)$|\.(sqlite|sqlite3|sql|sql\.gz)$|/storage/logs/[^/]+\.log$|/storage/app/(install-state\.json|install-code\.txt)$|/storage/framework/sessions/[^.][^/]*$|\.(pem|key)$|(^|/)auth\.json$'

FOUND="$(unzip -Z1 "$ZIP" | grep -E "$FORBIDDEN" || true)"

if [ -n "$FOUND" ]; then
    echo "DITOLAK: paket memuat file rahasia/data lokal:" >&2
    echo "$FOUND" | sed 's/^/  - /' >&2
    echo "Kirim .env terpisah (bukan di dalam zip) dan pakai APP_KEY produksi sendiri (php artisan pos:rotate-key)." >&2
    exit 1
fi

echo "OK: $ZIP bersih dari .env, database lokal, log, dan kunci"
