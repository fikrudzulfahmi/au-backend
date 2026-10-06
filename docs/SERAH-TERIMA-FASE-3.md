# SERAH-TERIMA — SIPANDU (status per 6 Oktober 2026)

Dokumen ini untuk melanjutkan pekerjaan **Fase 3** kapan pun, termasuk dari mesin lain.
Sumber kebenaran tetap `spesifikasi-aplikasi-presensi-smk.md` (Bagian 11 untuk rencana fase).

---

## 1. Status

| Fase | Isi | Status |
|---|---|---|
| 0 | Kerangka dua repo, autentikasi, waktu server, Info Sekolah | selesai |
| 1 | Master data, import/export, pengaturan, pengguna, audit log | selesai |
| 2 | Plotting kelas (termasuk wizard), plotting mapel, jam pelajaran, jadwal | selesai |
| 3 | Presensi & pengajuan (lokasi, jam kerja, GPS + foto, izin/dinas, persetujuan) | **belum** |

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
npm run dev                   # http://127.0.0.1:5173
```

Pada mesin lama, PHP 8.3 ada di `C:\php83\php.exe` (XAMPP 8.2 sengaja tidak diubah).
Semua perintah artisan/pest dijalankan dengan binari itu.

Perintah verifikasi:
```bash
cd au-backend  && vendor/bin/pint --test && php artisan test
cd au-frontend && npm run typecheck && npm run lint && npm run test && npm run build
```

## 3. Akun & data uji

- `admin` / `Sipandu#2026` — peran admin (hak kelola penuh)
- Kepala sekolah & wakasek: lihat `PegawaiSeeder`/`UserSeeder` (username = NIP)
- Seeder §10 menghasilkan: 3 jurusan, 6 kelas, 60 siswa, 10 pegawai (8 guru), 8 mapel,
  1 tahun pelajaran aktif + 2 semester, 2 pola jam (5 hari), 48 plotting mapel,
  120 entri jadwal tanpa bentrok.

> Ganti seluruh password contoh sebelum dipakai di produksi.

## 4. Yang sudah disiapkan untuk Fase 3 (jangan dibuat ulang)

- **`PenjagaHapus`** (`app/Support/PenjagaHapus.php`) otomatis aktif begitu tabel rujukan
  ada. FR-SIS-06 (siswa berpresensi tidak boleh dihapus), FR-KLS-05, dan FR-PLM-03 sudah
  memakainya — cukup membuat tabelnya, penjaga menyala sendiri.
- **Tabel `perangkat_pengguna`** (Fase 0) sudah ada beserta layanan reset perangkat
  (`PegawaiService::resetPerangkat`, BR-14) dan middleware token perangkat.
- **`PengaturanService`** sudah menyimpan `gps_max_akurasi_m`, `foto_max_sisi_px`,
  `foto_kualitas_jpeg`, `foto_target_maks_kb` (FR-LOK-05/06), dapat diubah dari UI
  Pengaturan Sistem.
- **`WaktuService`** (BR-13) sudah menjadi acuan waktu server tunggal.
- **`AturanBisnisException`** + render di `bootstrap/app.php`: lempar ini untuk pelanggaran
  BR/FR agar klien menerima `{message, code, errors?}` dengan pesan Bahasa Indonesia.
- **`ExcelService` / `EksporMasterService`** untuk ekspor & templat; `BerkasService`
  untuk kompresi gambar (dipakai kompresi logo, dapat dipakai kompresi foto presensi).

## 5. Yang perlu dibuat di Fase 3

Sesuai `FR-LOK` (5.10), `FR-PRS` (5.11), `FR-IZN` (5.12) dan tabel 7.4:

1. Migrasi: `lokasi_presensi`, `lokasi_pegawai` (bila dipakai), `jam_kerja`,
   `presensi_pegawai`, `pengajuan` (+ `pengajuan_luar_radius` bila dipisah).
2. Presensi masuk/pulang dengan GPS + foto dari kamera, watermark, kompresi ≤150 KB (BR-15..18).
3. Radius Haversine + lokasi default (BR-11, BR-12).
4. Perangkat terdaftar satu akun satu perangkat (BR-14) — tabel & layanannya sudah ada.
5. Pengajuan izin/sakit/dinas/cuti dan alur persetujuan (BR-17, BR-25).
6. Monitoring & persetujuan presensi luar radius (admin).
7. Halaman frontend: `/presensi/*`, `/pengajuan/*`, `/monitoring/*`, `/persetujuan/*`.

## 6. Catatan teknis yang sudah menempel (berlaku untuk fase berikutnya)

- **`tahun_pelajaran.status`** nilainya string `'aktif'`; konstantanya
  `TahunPelajaran::STATUS_AKTIF` (bukan `::AKTIF`).
- **`Semester::label()` adalah method**, bukan kolom. Memakai `$semester->label` (akses
  atribut) melempar galat resolusi relasi.
- **Jangan memakai `->values()` pada Eloquent Builder** — `values()` milik Collection;
  tambahkan `->get()` lebih dulu.
- **Kolom yang sama di dua tabel wajib dikualifikasi** setelah `join` (mis.
  `plotting_kelas.tahun_pelajaran_id`), jika tidak MySQL melempar 1052.
- **`ResponsDaftar::buat($paginator, $resource, $metaTambahan)`** — paginasi dibongkar
  manual; membungkus `Resource::collection($paginator)` di `response()->json()` akan
  membuang meta.
- **Penghapusan yang bergantung tabel fase berikutnya** memakai `PenjagaHapus`, bukan
  `Schema::hasTable` ad hoc.
- **Bentrok dijaga berlapis**: validasi aplikasi (pesan yang menyebut pelakunya) +
  indeks unik di database sebagai jaring terakhir.
- **Seeder bersifat idempoten** (`updateOrCreate`) sehingga aman diulang.
- Di **Windows**, `taskkill /PID` memerlukan `MSYS_NO_PATHCONV=1`; jalankan Vite dengan
  `--strictPort` agar tidak diam-diam pindah port.

## 7. Hal yang masih terbuka (bukan penghalang)

- **Advisory dependensi (dev saja):** `vitest` 3.2.7, `tinypool`, `@vitest/mocker`.
  Tidak ikut ke bundel produksi. Perbaikannya menuntut vitest 5.x (breaking).
- **Ukuran bundel frontend (ditunda — keputusan pemilik, 6 Oktober 2026).**
  Kondisi terukur pada `au-frontend` commit `775011d`:

  | Bagian | Ukuran | Gzip |
  |---|---|---|
  | `index-BBV1YSPP.js` (bundel utama) | **580,76 kB** | 165,86 kB |
  | `leaflet-src-C8_wPyq5.js` | 150,05 kB | 43,59 kB |
  | `LandingPage-nCmY3U84.js` | 14,81 kB | 4,42 kB |
  | `index-B6F-SL7P.css` | 67,39 kB | 19,90 kB |
  | precache PWA | 967,46 KiB | 33 entri |

  Ambang saran Vite adalah 500 kB, jadi peringatan `(!) Some chunks are larger than 500 kB`
  muncul saat build. **Ini peringatan, bukan galat — build dan PWA tetap sukses.**
  Pertumbuhannya: 526 kB (akhir Fase 0/1) → 580,76 kB (akhir Fase 2), yaitu **+55 kB**.
  Penyebabnya halaman-halaman Fase 2 diimpor secara statis pada `src/app/router.tsx`,
  sehingga semuanya ikut ke bundel awal meski peran pengguna tidak pernah membukanya.

  Perbaikan yang disarankan, dari yang paling menguntungkan:
  1. **`React.lazy` + `<Suspense>` per rute** pada `src/app/router.tsx`. Ini yang paling
     besar hasilnya karena memecah halaman terberat. Kandidat pertama:
     `JadwalPage`, `PlottingKelasPage`, `PlottingMapelPage`, `NaikKelasPage`,
     `JamPelajaranPage`, lalu halaman `master/*`.
  2. **`build.rollupOptions.output.manualChunks`** pada `vite.config.ts` untuk memisahkan
     vendor (react, `@tanstack/react-query`, dsb) agar tidak ikut berubah setiap rilis.
  3. `build.chunkSizeWarningLimit` — **jangan** dipakai untuk menyembunyikan peringatan;
     ini hanya menaikkan ambang tanpa memperbaiki apa pun.

  Rambu sebelum mengerjakan: code-splitting mengubah pengalaman pemuatan (muncul
  `Suspense` fallback singkat), jadi harus diuji di peramban — bukan hanya lewat build —
  terutama pada jaringan sekolah yang lambat. Ukur ulang dengan `npm run build` dan
  bandingkan angka pada tabel di atas. Jangan kerjakan bersamaan dengan perubahan fitur,
  supaya jelas mana yang menurunkan ukuran bundel.
- **Ekspor PDF** master data & laporan baru dikerjakan Fase 5 (memerlukan kop & tanda
  tangan). Permintaan `format=pdf` pada Fase 1 dijawab 422 dengan pesan jelas, bukan 500.
- **npm `allow-remote`**: `.npmrc` pada repo frontend menyetel `allow-remote=all` karena
  npm 12 memblokir paket remote (dependensi turunan Tailwind 4). Rambu: hanya untuk paket
  yang sudah ada di `package-lock.json`.

## 8. Riwayat commit penanda

| Repo | Commit | Isi |
|---|---|---|
| au-backend | `6778b49` | Fase 2 backend (plotting, jam, jadwal) |
| au-frontend | `01dde42` | Fase 2 frontend (halaman plotting & akademik) |

Uji saat serah-terima: backend **154 lulus / 611 assertion** (Pint bersih),
frontend **31 lulus** (typecheck, lint, build bersih), CI hijau di kedua repo.
