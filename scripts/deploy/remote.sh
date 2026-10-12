#!/usr/bin/env bash
# Dijalankan di server cPanel lewat SSH oleh .github/workflows/deploy.yml (docs/deploy.md).
#   pre  : mode perawatan + backup database (sebelum file diganti)
#   post : migrasi, cache, storage link, aktifkan kembali
# Variabel: PHP_BIN (path PHP CLI 8.2+), DEPLOY_BACKUP (true/false). Direktori kerja = folder project.
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
step="${1:-}"

artisan() { "$PHP_BIN" artisan "$@"; }

if [ ! -f .env ]; then
    echo "File .env belum ada di $(pwd). Instal aplikasi dulu (docs/deploy.md)." >&2
    exit 1
fi

case "$step" in
    pre)
        # Deploy pertama: belum ada artisan/vendor → lewati.
        if [ -f artisan ] && [ -d vendor ]; then
            artisan down --retry=60 || true
            if [ "${DEPLOY_BACKUP:-true}" = "true" ]; then
                # Backup gagal → file belum diganti, aman diaktifkan kembali lalu hentikan deploy.
                if ! artisan backup:run --only-db --disable-notifications; then
                    artisan up
                    echo "Backup database gagal; deploy dibatalkan (situs aktif kembali)." >&2
                    exit 1
                fi
            fi
        fi
        ;;
    post)
        artisan migrate --force
        [ -L public/storage ] || artisan storage:link
        artisan optimize:clear
        artisan optimize
        artisan filament:optimize
        artisan queue:restart
        artisan up
        echo "Deploy selesai: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
        ;;
    *)
        echo "Pemakaian: remote.sh pre|post" >&2
        exit 1
        ;;
esac
