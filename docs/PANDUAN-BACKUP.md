# PANDUAN BACKUP & PEMULIHAN — SIPANDU

Dokumen ini menjelaskan apa yang harus dicadangkan dari instalasi SIPANDU,
cara mencadangkannya, cara memulihkannya, dan **hubungan retensi foto (BR-30)
dengan pencadangan**.

> Catatan: dokumen ini adalah panduan operasional. Perintah pencadangan
> (`mysqldump`, `tar`) adalah alat standar MySQL/Unix; SIPANDU sendiri **tidak**
> menyediakan fitur pencadangan bawaan di dalam kode.

---

## 1. Apa yang harus dicadangkan

| # | Benda | Letak | Wajib? |
|---|---|---|---|
| 1 | **Basis data** | Basis data `sipandu` (tabel: 7.1 & 7.6 spesifikasi) | **Wajib** |
| 2 | **Berkas foto privat** | `storage/app/private/` (disk `local`) — foto presensi & lampiran jurnal | **Wajib** |
| 3 | **Berkas `.env`** | akar repo `au-backend/.env` | **Wajib** |
| 4 | **`APP_KEY`** | nilai di dalam `.env` | **Wajib** (rahasia) |
| 5 | Pengaturan sekolah, kop & TTD | Tabel `pengaturan`, `profil_sekolah`, `penandatangan` — **sudah termasuk** di basis data | (tercakup #1) |
| 6 | Lampiran pengajuan izin | Disk privat (bagian dari `storage/app/private`) | (tercakup #2) |

Sumber letak file: `config/filesystems.php` (disk `local` → `storage/app/private`),
`app/Services/BerkasService.php` (foto disimpan via `Storage::disk('local')`),
K-36 & K-69.

> **Mengapa `APP_KEY` wajib ikut?** Kunci ini dipakai untuk enkripsi sesi dan
> cookie. Pemulihan basis data + berkas tanpa `APP_KEY` yang sama akan membuat
> data terenkripsi tidak terbaca. Simpan `APP_KEY` di tempat aman terpisah
> (vault), jangan pernah di-commit ke git.

---

## 2. Cara mencadangkan

### 2.1 Basis data

```bash
# Cadangan penuh
mysqldump -u root -p --single-transaction --routines --triggers \
  sipandu > backup/sipandu-$(date +%F).sql

# Opsi: cadangan terkompresi
mysqldump -u root -p --single-transaction sipandu | gzip > backup/sipandu-$(date +%F).sql.gz
```

Rekomendasi: jadwalkan harian (mis. via cron di server) dan simpan salinan di
lokasi **di luar server** (object storage / server lain).

### 2.2 Berkas foto & lampiran

```bash
tar -czf backup/multimedia-$(date +%F).tar.gz storage/app/private
```

### 2.3 `.env` dan `APP_KEY`

```bash
cp .env backup/env-$(date +%F).bak     # simpan di tempat aman, bukan di git
```

`.env` **diabaikan git** (`.gitignore`), jadi tidak akan pernah masuk repositori.
Cadangkan secara manual.

Sumber: `.gitignore`, `.env.example`.

---

## 3. Cara memulihkan

1. **Siapkan kode**: `composer install` pada versi repo yang sesuai dengan
   cadangan.
2. **Pulihkan `.env`** dan `APP_KEY`:
   - Salin file `.env` cadangan ke akar repo, **atau**
   - salin `.env.example` lalu isi `APP_KEY` dengan nilai asli dari cadangan
     (mutlak sama agar data terenkripsi terbaca).
3. **Pulihkan basis data**:

   ```bash
   mysql -u root -p sipandu < backup/sipandu-2026-10-06.sql
   # bila terkompresi:
   gunzip -c backup/sipandu-2026-10-06.sql.gz | mysql -u root -p sipandu
   ```

4. **Pulihkan berkas foto**:

   ```bash
   tar -xzf backup/multimedia-2026-10-06.tar.gz -C /   # dari akar repo
   ```

   Pastikan `storage/app/private/` memiliki izin tulis.
5. **Verifikasi**: jalankan `php artisan migrate:status` (tidak ada migrasi
   tertunda) dan periksa endpoint `GET /api/v1/waktu-server`.

---

## 4. Hubungan dengan retensi foto (BR-30) — **penting**

SIPANDU menghapus berkas foto secara **sengaja** sesuai BR-30, dan penghapusan
tersebut **tidak dapat dipulihkan** dari dalam aplikasi.

### Apa yang terjadi

- Foto presensi dan lampiran jurnal disimpan selama satu tahun pelajaran
  (`A-07`, `A-12`).
- Setelah admin menandai tahun pelajaran berstatus **`selesai`**
  (`FR-TP-05`), perintah `presensi:bersihkan-foto` **menghapus berkas fisik**
  foto tahun pelajaran tersebut. Berkas dihapus dari disk, kolom path diisi
  `NULL`, dan `foto_dihapus_pada` ditandai.
- **Data teks tetap utuh**: waktu, koordinat, akurasi, status, dan validasi
  presensi tidak pernah dihapus. Baris presensi dan jurnal juga tidak dihapus.
- Berjalan otomatis tiap hari pukul **01:30 Asia/Jakarta** (`routes/console.php`,
  K-92), dan dapat juga dijalankan manual admin melalui
  `POST /api/v1/tahun-pelajaran/{id}/bersihkan-foto` (K-93).

Sumber: `app/Console/Commands/BersihkanFotoPresensi.php`,
`app/Services/RetensiFotoService.php`, BR-30, A-07, A-12, K-88..K-93.

### Konsekuensi yang harus dipahami pengelola

1. **Setelah tahun pelajaran ditandai `selesai` dan tugas retensi berjalan,
   foto tidak lagi dapat dipulihkan** dari aplikasi — hanya ada di cadangan.
2. **Satu-satunya cara menyimpan foto lintas tahun adalah cadangan.** Bila
   sekolah berkewajiban menyimpan foto presensi lebih lama dari satu tahun
   pelajaran, **cadangan berkala wajib** dan harus dijalankan **sebelum** tahun
   pelajaran ditandai `selesai`.
3. **Perilaku ini disengaja** (agar disk tidak tumbuh tanpa batas), bukan bug.
   Ukur kebutuhan penyimpanan sebelum memutuskan kebijakan retensi.
4. **Endpoint penyajian foto** untuk berkas yang sudah dibersihkan menjawab
   **404 berpesan**, bukan 500 — hal ini normal, bukan kerusakan sistem (K-36,
   K-69, K-89).
5. **Mode kering** `php artisan presensi:bersihkan-foto --kering` melaporkan
   berapa berkas **akan** dihapus tanpa menyentuh disk maupun kolom — gunakan
   untuk memeriksa sebelum pembersihan sungguhan.

### Saran kebijakan

- Jadwalkan pencadangan multimedia **harian** dan simpan di luar server.
- Sebelum menandai akhir tahun pelajaran: buat pencadangan penuh (basis data +
  `storage/app/private`) dan arsipkan.
- Uji pemulihan secara berkala; cadangan yang belum pernah diuji bukan jaminan.
