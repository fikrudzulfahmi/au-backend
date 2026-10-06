# SERAH-TERIMA — SIPANDU (status per 6 Oktober 2026)

Dokumen ini untuk melanjutkan pekerjaan **Fase 4** kapan pun, termasuk dari mesin lain.
Sumber kebenaran tetap `spesifikasi-aplikasi-presensi-smk.md` (Bagian 11 untuk rencana fase).

---

## 1. Status

| Fase | Isi | Status |
|---|---|---|
| 0 | Kerangka dua repo, autentikasi, waktu server, Info Sekolah | selesai |
| 1 | Master data, import/export, pengaturan, pengguna, audit log | selesai |
| 2 | Plotting kelas (termasuk wizard), plotting mapel, jam pelajaran, jadwal | selesai |
| 3 | Presensi GPS + foto, luar radius, izin/sakit/dinas/cuti, monitoring, persetujuan | selesai |
| 4 | Jurnal pembelajaran + presensi siswa | **belum** |

Repositori (publik):
- Backend `https://github.com/fikrudzulfahmi/au-backend`
- Frontend `https://github.com/fikrudzulfahmi/au-frontend`

## 2. Cara menjalankan di mesin baru

Yang perlu ada: **PHP 8.3** (bukan 8.2 — Pest 3 menuntut ≥8.3), Composer, Node 22, MySQL/MariaDB.

```bash
# Backend
cd au-backend
cp .env.example .env          # isi DB & APP_KEY
php artisan key:generate
composer install
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000

# Frontend
cd au-frontend
cp .env.example .env
npm ci
npm run dev -- --strictPort   # http://127.0.0.1:5173
```

Pada mesin lama, PHP 8.3 ada di `C:\php83\php.exe` (XAMPP 8.2 sengaja tidak diubah).

Perintah verifikasi — **jalankan tanpa memipe ke `grep`**, karena perekam bukti verifikasi
hanya mengenali perintah kanonik apa adanya:
```bash
cd au-backend  && vendor/bin/pint --test && php artisan test --compact
cd au-frontend && npm run typecheck && npm run lint && npm run test && npm run build
```

## 3. Akun & data uji

- `admin` / `Sipandu#2026` — peran admin (hak kelola penuh)
- Kepala sekolah & wakasek: lihat `UserSeeder` (username = NIP)
- Seed §10: 3 jurusan, 6 kelas, 60 siswa, 10 pegawai (8 guru), 8 mapel, 1 tahun pelajaran
  aktif + 2 semester, 2 pola jam (5 hari), 48 plotting mapel, 120 entri jadwal tanpa
  bentrok, **2 lokasi presensi (1 default)**, dan **14 baris jam kerja**.

> Ganti seluruh password contoh sebelum dipakai di produksi.

## 4. Yang sudah disiapkan untuk Fase 4 (jangan dibuat ulang)

- **`PengajuanService::berhalanganPada($pegawai, $tanggal)`** — tepat untuk KP-4.6
  (sesi pada hari izin disetujui tampil "Berhalangan") dan BR-26 (kepatuhan jurnal).
- **`PresensiPegawai::VALIDASI_DIHITUNG`** + `dihitungHadir()` — syarat BR-19: jurnal
  tanggal T hanya boleh dibuat bila guru punya presensi masuk `valid`/`disetujui`/`menunggu`.
- **`PresensiPegawai::sudahMasuk()`** dan relasi `pegawai` — gerbang BR-19 di server.
- **`BerkasService`** — `simpanGambarTerkompres()` untuk foto kegiatan jurnal (maks 3,
  FR-JRN-02); `simpanFotoPresensi()` bila ingin watermark.
- **`JadwalService`** — sumber sesi: entri jadwal berurutan pada plotting mapel & hari
  yang sama digabung menjadi satu sesi (FR-JRN-01).
- **`PenjagaHapus`** — pola penjaga penghapusan yang bergantung tabel fase berikutnya
  (mis. jadwal yang sudah punya jurnal; FR-JRN-07 memakai pola serupa).
- **`AturanBisnisException`** + render di `bootstrap/app.php` — lempar ini agar klien
  menerima `{message, code, errors?}` berbahasa Indonesia.
- **`WaktuService`** (BR-13) dan **`AuditLogService`** (BR-20: catat setiap perubahan jurnal).

## 5. Yang perlu dibuat di Fase 4

Sesuai `FR-JRN` (5.13) dan skema 7.5:

1. Migrasi `jurnal`, `jurnal_foto`, `presensi_siswa` — UQ `jurnal` (plotting_mapel_id,
   tanggal, jam_ke_mulai) menegakkan BR-21; UQ `presensi_siswa` (jurnal_id, siswa_id).
2. `SesiService` — membentuk sesi dari jadwal (gabung entri berurutan, FR-JRN-01) dan
   menandainya Belum / Sudah / Berhalangan.
3. `JurnalService` — simpan jurnal + presensi siswa dalam satu transaksi; gerbang BR-19;
   lindungi BR-21; catat setiap perubahan ke `audit_log` (BR-20).
4. Presensi siswa: siswa `aktif` yang terplot di kelas pada tahun pelajaran jurnal
   (BR-22), status hanya H/S/I/A, default Hadir, tombol "Semua Hadir".
5. Halaman frontend: `/mengajar/jurnal/isi/:sesi`, `/mengajar/jurnal/riwayat`,
   `/wali-kelas/rekap`, dan kartu beranda "jadwal hari ini + status jurnal".

## 6. Catatan teknis yang sudah menempel (berlaku untuk fase berikutnya)

- **`collection[$kunci]` MELEMPAR galat bila kunci tidak ada** — pakai `->get($kunci)`.
  `?->` tidak menolong karena galatnya terjadi saat pengambilan kunci; ini pernah membuat
  `GET /jam-kerja` gagal 500 pada database kosong.
- **`getFillable()` sudah berupa daftar nama kolom** — jangan dibungkus `array_keys()`,
  jika tidak tidak ada satu pun kolom yang lolos pemeriksaan.
- **`TahunPelajaran::STATUS_AKTIF`** (bukan `::AKTIF`); **`Semester::label()` adalah method**,
  bukan kolom.
- **Jangan memakai `->values()` pada Eloquent Builder** — tambahkan `->get()` lebih dulu.
- **Kolom yang sama di dua tabel wajib dikualifikasi** setelah `join` (MySQL 1052).
- **`ResponsDaftar::buat($paginator, $resource, $metaTambahan)`** — paginasi dibongkar manual.
- **FormData + axios**: `lib/api.ts` melepas `Content-Type` bawaan untuk FormData; tanpa itu
  penguraian multipart rusak.
- **Intervention Image v3**: `drawRectangle($x, $y, $init)` — lebar/tinggi diatur di dalam
  closure; teks dapat digambar tanpa berkas font (font bawaan GD) sehingga tetap jalan di Linux.
- **Uji presensi memakai `travelTo()`** karena `WaktuService` memakai `CarbonImmutable::now()`.
- Di **Windows**, `taskkill /PID` butuh `MSYS_NO_PATHCONV=1`; jalankan Vite dengan `--strictPort`.

## 7. Hal yang masih terbuka (bukan penghalang)

- **Ukuran bundel frontend**: precache 1035 KiB setelah Fase 3 (leaflet kini dipakai di
  beberapa halaman) dan bundel utama sedikit di atas ambang saran Vite 500 kB. Perbaikan
  yang disarankan: `React.lazy` + `<Suspense>` per rute pada `src/app/router.tsx`
  (kandidat terberat: halaman presensi, monitoring, peta lokasi), lalu `manualChunks` untuk
  vendor. **Jangan** memakai `chunkSizeWarningLimit` untuk menyekat peringatan. Uji di
  peramban karena `Suspense` mengubah pengalaman pemuatan, dan jangan kerjakan bersamaan
  dengan perubahan fitur.
- **Advisory dependensi (dev saja)**: `vitest` 3.2.7, `tinypool`, `@vitest/mocker`. Tidak
  masuk bundel produksi; perbaikannya menuntut vitest 5.x (breaking).
- **Ekspor PDF** laporan baru dikerjakan Fase 5 (kop & tanda tangan). Permintaan `format=pdf`
  dijawab 422 dengan pesan jelas, bukan 500.
- **Retensi foto (BR-30)**: kolom `foto_dihapus_pada` dan `lampiran_dihapus_pada` sudah ada;
  tugas terjadwal `presensi:bersihkan-foto` baru dibuat Fase 7.
- **`allow-remote`**: `.npmrc` pada repo frontend menyetel `allow-remote=all` (dependensi
  turunan Tailwind 4). Rambu: hanya untuk paket yang sudah ada di `package-lock.json`.

## 8. Riwayat commit penanda

| Repo | Commit | Isi |
|---|---|---|
| au-backend | `6f8df7c` | Fase 3 backend (presensi, pengajuan, monitoring) |
| au-frontend | `5748d73` | Fase 3 frontend (presensi kamera+GPS, pengaturan) |

Uji saat serah-terima: backend **219 lulus / 855 assertion** (Pint bersih, 248 berkas),
frontend **31 lulus** (typecheck, lint, build bersih).
