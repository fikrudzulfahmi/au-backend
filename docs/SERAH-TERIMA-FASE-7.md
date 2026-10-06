# SERAH-TERIMA — Fase 7 (Tugas Terjadwal, Dashboard, PWA, Optimasi & Dokumentasi)

Status saat serah terima: **Fase 0–6 selesai, ter-verifikasi.**

| | au-backend |
|---|---|
| Uji | **343 lulus / 1393 assertion** (Fase 5: 305/1197; Fase 6: +38 uji / +196 assertion) |
| Pint | PASS — 299 berkas |
| Rute API | **175** (Fase 5: 158; Fase 6: +17) |
| Waktu suite penuh | ± 70 detik |

Fase 6 sudah menambahkan: Pengumuman (5.20/BR-36), inti Layar TV (5.19/BR-32/33/35/37 + KP-6.6),
dan endpoint Landing (5.21/BR-34). Rincian ada di `docs/SERAH-TERIMA-FASE-6.md` dan baris K-75..K-84
pada `CATATAN-KEPUTUSAN.md`.

---

## 1. Cakupan Fase 7

### 1.1 Tugas terjadwal `presensi:bersihkan-foto` (BR-30)

- `BR-30` menetapkan retensi foto presensi: foto lama dibersihkan otomatis agar disk tidak
  tumbuh tanpa batas. Perlu **perintah artisan** (mis. `presensi:bersihkan-foto`) plus
  penjadwalan di `routes/console.php`.
- Wajib menjaga: berkas yang sudah tiada tidak dianggap gagal (idempoten), foto jurnal
  (BR-30 hanya menyebut foto presensi) tidak ikut terhapus, dan ada **uji** yang membuktikan
  berkas lama benar-benar hilang sementara berkas baru tetap ada.
- Catatan dari K-69: foto jurnal disimpan di disk privat dengan aturan berbeda; jangan
  menyatukan keduanya dalam satu perintah tanpa membaca ulang `BR-30`.
- Endpoint penyajian foto harus tetap menjawab **404 berpesan** (bukan 500) untuk berkas
  yang sudah dibersihkan.

### 1.2 Dashboard (5.17)

- Ringkasan angka untuk peran pemantau, memakai **aturan yang sama** dengan laporan (Fase 5)
  dan TV (Fase 6) — jangan menghitung ulang dari tabel mentah (warisan jebakan BR-37).
- Panggil `LaporanPresensiService` / `LaporanJurnalService` / `MonitoringPresensiService`;
  bila perlu cache per tanggal seperti `TvRekapService` (KP-6.6).
- Perhatikan matriks Bagian 2: dashboard untuk guru berbeda dari dashboard pemantau.

### 1.3 PWA (ikon, instal ke layar utama)

- Sisi **frontend** (`au-frontend`, repo terpisah): manifest, ikon pada beberapa ukuran,
  service worker, tombol **Pasang Aplikasi** pada landing (FR-LND-02) dan beranda.
- Sisi backend: pastikan **favicon/logo** dari Info Sekolah tersedia bagi manifest bila
  spesifikasi menuntutnya. Periksa kembali A-13 dan FR-LND-11 (performa; Lighthouse
  performa mobile ≥ 90, tanpa SSR).

### 1.4 Optimasi kinerja jam sibuk

- Sasaran: presensi masuk serentak pagi hari. Kandidat perbaikan: indeks kolom penyaring
  (`presensi_pegawai.tanggal`, `presensi_pegawai.pegawai_id`, `jurnal.tanggal+semester_id`),
  kurangi query per baris pada monitoring, dan **hitung** dampaknya (jumlah query per
  permintaan) alih-alih mengira-ngira.
- Ukur sebelum/sesudah dengan angka nyata (mis. waktu rata-rata endpoint tertinggi) dan
  catat di `CATATAN-KEPUTUSAN.md`.

### 1.5 Dokumentasi

- Panduan pengguna: **admin**, **guru**, dan **pegawai** (setiap peran apa yang bisa/tidak).
- Panduan instalasi (backend Laravel + frontend Vite, DB MariaDB 10.4, PHP 8.3) dan
  panduan **backup** (dump database + berkas privat `storage/app/private`).
- Catatan batasan deteksi fake GPS (`FR-SEC-08`) — tulis **apa adanya**: apa yang diperiksa,
  apa yang tidak dapat dijamin, dan bagaimana admin melihat indikasinya.

---

## 2. Fondasi yang sudah siap dan TIDAK perlu dibangun ulang

| Fondasi | Letak | Dipakai untuk |
|---|---|---|
| Layanan laporan (angka otoritatif) | `app/Services/Laporan/LaporanPresensiService.php`, `LaporanJurnalService.php` | Dashboard & laporan apa pun (BR-37) |
| Monitoring status presensi | `app/Services/MonitoringPresensiService.php` | Angka presensi harian |
| Rincian TV + cache per tanggal | `app/Services/TvRekapService.php` | Pola cache 15 detik (KP-6.6) — **bukan** dijadikan sumber angka baru |
| Pengumuman (BR-36) | `app/Models/Pengumuman.php` (`scopeTayang`), `app/Services/PengumumanService.php` | Dashboard/pengumuman beranda |
| Info sekolah | `ProfilSekolah`, `PengaturanTtd`, `Penandatangan` | Ikon/favicon PWA, judul, footer |
| Pengaturan sistem kunci/nilai | `app/Services/PengaturanService.php` (DEFAULT + TIPE) | Semua opsi baru |
| Otorisasi per peran | trait `MemakaiPegawai`, middleware `peran:` | Endpoint dashboard |
| Waktu otoritatif | `CarbonImmutable::now()`, zona `Asia/Jakarta` | Uji tugas terjadwal dengan `travelTo()` |
| Helper uji | `tests/Pest.php` (`siapkanPresensi()`, `siapkanJurnal()`, `pasangJamKerja()`, `fotoUji()`, `teksPdf()`, …) | Uji Fase 7 |

---

## 3. Jebakan yang sudah terbukti menggigit (jangan diulang)

1. **Jangan menghitung ulang angka yang sudah ada di laporan.** Ini yang membuat BR-37 gagal
   pada draf pertama Fase 6; dashboard mewarisi risiko yang sama.
2. **Tabel terpisah lebih kuat daripada penyaringan.** Token TV dipisah, bukan "disaring".
   Terapkan pola yang sama untuk hal baru (mis. token perangkat PWA bila Spec menuntutnya).
3. **Uji harus membandingkan ANGKA, bukan status 200.** Uji Fase 6 membandingkan `ringkasan`
   TV dengan keluaran layanan laporan secara langsung.
4. **Memindai kunci JSON secara rekursif** adalah cara paling andal menguji aturan
   "tidak boleh memuat data pribadi" (BR-33/BR-34).
5. **`$collection[$kunci]` melempar galat** bila kunci tidak ada — pakai `->get()`.
6. **`getFillable()` sudah berupa daftar** — `array_keys()` selalu salah.
7. **Model tanpa `$fillable` gagal saat `create()`.**
8. **`ResponsDaftar::buat($paginator, Resource::class)`** — urutan argumen mudah terbalik.
9. **Jangan `groupBy` pada kolom non-PK** untuk deduplikasi; pakai `DISTINCT`.
10. **Nilai `0.0` lewat JSON menjadi `0`** — jangan `toBe(0.0)`; `filter()` tanpa callback
    ikut membuang `0`.
11. **MariaDB 10.4 menolak `timestamp NOT NULL` tanpa default** (K-83) — pakai `dateTime`.
12. **`diffInDays()` pada Carbon 3 bernilai bertanda** (K-84).
13. **Jangan `ob_end_clean()` manual** sebelum `response()->download()`.
14. **Zona waktu `Asia/Jakarta`**; uji waktu memakai `travelTo()`.
15. **Jangan pasang `watch_patterns` pada dev server** (`artisan serve`, `npm run dev`).
16. **Verifikasi dengan perintah kanonik TANPA pipa `grep`**; laporkan angkanya eksplisit
    (suite PHP tidak pernah memenuhi penanda bukti kanonik walau lulus).

---

## 4. Alur kerja yang terbukti (Fase 6)

1. Satu **irisan vertikal tipis** dulu: migrasi → model → satu endpoint → satu uji hijau.
2. Jalankan hanya berkas uji fase itu (`vendor/bin/pest tests/Feature/Fase7`) sambil
   mengerjakan; suite penuh **sekali** di akhir.
3. **Commit bertahap**: setiap tonggak yang sudah hijau (Pint + uji) langsung di-commit.
   Bekerja "sampai selesai baru commit" pernah kehilangan pekerjaan pada fase sebelumnya.
4. Pesan commit Bahasa Indonesia bergaya Conventional Commits, menyebut ID aturan
   (mis. `feat(fase-7): tugas terjadwal presensi:bersihkan-foto [BR-30]`).

---

## 5. Di luar cakupan (JANGAN dibuat)

Notifikasi WhatsApp/SMS/email otomatis; integrasi Dapodik; penilaian/rapor; keuangan; akun
siswa; aplikasi native; SSR/SEO; layar TV interaktif atau kontrol jarak jauh (**TV hanya
tampilan baca**); mode gelap di aplikasi (hanya TV yang bertema gelap); multi-sekolah;
pengenalan wajah; guru pengganti/team teaching.
