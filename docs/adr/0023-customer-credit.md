# ADR 0023: Kasbon (piutang pelanggan)

- Status: Diterima
- Tanggal: 2026-10-14
- Pengambil keputusan: Gagan Suganda (keputusan bawaan diusulkan Claude, disetujui lewat "lanjut hingga selesai")
- Terkait: SPEC Q105–Q107, ADR 0013 (member, bukti QR/PIN), ADR 0017 (kas masuk laci), ADR 0018 (retur)

## Konteks

Warung & UMKM sering memberi "kasbon" ke pelanggan tetap: belanja sekarang, bayar nanti. Tanpa pencatatan, utang
pelanggan hanya ada di buku tulis dan pelunasan tunai membuat selisih laci.

## Keputusan

| Topik | Keputusan |
|---|---|
| Siapa | Hanya **member** (identitas jelas), dengan **bukti QR/PIN member** seperti bayar poin |
| Aktif | Pengaturan → Program member → **Kasbon**: aktif/nonaktif + batas bawaan; metode bayar sistem **Kasbon** (`receivable`) aktif mengikuti setelan, tidak bisa diubah di menu Metode bayar |
| Batas | `members.credit_limit` (kosong = batas bawaan); saldo + kasbon baru ≤ batas, batas 0/kosong = tidak boleh kasbon |
| Saldo | `members.receivable_balance` + ledger `receivable_entries` (`charge`, `payment`, `void`, `refund`), baris member dikunci |
| Void / retur | Void membalik kasbon order; retur mengurangi kasbon sebesar bagian yang dibayar kasbon (sisanya uang) |
| Pelunasan | `POST /members/{id}/receivable-payments` (aplikasi), kasir web **Kasbon**, dashboard detail member; tunai di perangkat dengan shift = kas masuk alasan **Pelunasan kasbon** (masuk `expected_cash`); `id` dari klien = idempotency key; ≤ saldo |
| Laporan | Daftar member berkasbon (saldo, kasbon tertua) di menu Member; total piutang di laporan Member |

## Konsekuensi

- Positif: utang pelanggan & pelunasan tercatat, laci tetap cocok.
- Negatif: tanpa bunga/jatuh tempo/pengingat otomatis; pelunasan dari dashboard (tanpa laci) dicatat di luar shift.
