# SERAH-TERIMA — Fase 5 (Laporan & Dokumen Resmi)

Status saat serah terima: **Fase 0–4 selesai, ter-verifikasi, dan ter-backup.**
Backend `au-backend` @ `50b8026`, frontend `au-frontend` @ `3b819c3`, CI keduanya hijau.

---

## 1. Yang sudah siap dan TIDAK perlu dibangun ulang

| Fondasi | Letak | Dipakai untuk |
|---|---|---|
| Rekap presensi siswa (H/S/I/A + persentase, filter periode) | `JurnalService::rekapPresensiSiswa()` | FR-LAP-06 — tinggal ditambah filter mapel dan ekspor |
| `ResponsDaftar::buat($paginator, Resource::class)` | `app/Support/ResponsDaftar.php` | Semua daftar laporan; **urutan argumennya mudah terbalik**, sudah pernah salah sekali |
| PDF | `dompdf` v3.1.2 (terpasang sejak Fase 1) | KP-5.1 |
| Excel | `phpspreadsheet` v5.10.0 (terpasang sejak Fase 1) | KP-5.1 |
| Kop surat & penandatangan | `PengaturanTtd`, `Penandatangan`, `ProfilSekolah.kop_baris1..3` | KP-5.1/5.2 |
| Hari libur | model `HariLibur` + tabelnya | BR-24 (alpa) & BR-26 (kepatuhan) |
| Presensi pegawai lengkap | `presensi_pegawai` (+ koreksi admin, luar radius) | FR-LAP-01..05 |
| Jurnal + presensi siswa | `jurnal`, `presensi_siswa` | FR-LAP-07..09 |
| Sesi berhalangan | `JurnalService::berhalangan()` / `PengajuanService::berhalanganPada()` | KP-5.4 — sesi berhalangan **tidak** dihitung belum terisi |

---

## 2. Cakupan Fase 5

### 2.1 Laporan (FR-LAP-01..09)

| Kode | Laporan | Catatan pengerjaan |
|---|---|---|
| FR-LAP-01 | Rekap presensi pegawai | Butuh gabungan: hadir, terlambat (jumlah **dan total menit**), pulang cepat, tidak presensi pulang, izin/sakit/dinas/cuti, alpa, luar radius disetujui, persentase |
| FR-LAP-02 | Detail presensi harian per pegawai | Versi web menampilkan tautan foto (endpoint berpelindung sudah ada) dan peta |
| FR-LAP-03 | Presensi harian semua pegawai (satu tanggal) | |
| FR-LAP-04 | Rekap izin/sakit/dinas/cuti per periode | |
| FR-LAP-05 | Rekap presensi luar radius (disetujui/ditolak/menunggu) | |
| FR-LAP-06 | Rekap presensi siswa dari jurnal | **Sebagian sudah ada** (`rekapPresensiSiswa`); kurang filter mapel dan ekspor |
| FR-LAP-07 | Daftar jurnal | Filter periode/guru/kelas/mapel |
| FR-LAP-08 | Rekap kepatuhan jurnal per guru | Paling rumit — lihat §2.3 |
| FR-LAP-09 | Rekap jam mengajar terlaksana per guru | |

**Wajib untuk semua laporan (5.14):** filter periode (hari ini, minggu ini, bulan, rentang),
tampil di web, **ekspor PDF dan Excel**, memakai kop surat dan blok tanda tangan dari
pengaturan. PDF resmi memuat judul, periode, tanggal cetak, dan penandatangan.

### 2.2 Kop surat & tanda tangan (5.15, FR-KOP)

Perubahan kop/penandatangan/tanggal penetapan harus **langsung tercermin pada laporan
berikutnya** (KP-5.2) — artinya laporan membaca dari pengaturan saat dicetak, bukan
menyalin nilainya ke baris laporan.

### 2.3 Dua aturan yang paling mudah salah

- **BR-24 — alpa.** Bila periode laporan memuat hari libur atau pegawai punya izin
  disetujui pada hari itu, hari tersebut **tidak** boleh dihitung sebagai alpa.
  Mengabaikan ini membuat rekap menuduh pegawai alpa pada hari libur — cacat yang langsung
  terlihat oleh pengguna dan merusak kepercayaan pada seluruh laporan.
- **BR-26 — kepatuhan jurnal.** Sesi pada hari izin disetujui ("Berhalangan") dan sesi pada
  hari libur **tidak dihitung** sebagai jurnal belum terisi. Penyebutnya pun hanya sesi
  terjadwal pada hari kerja non-libur. `JurnalService::berhalangan()` sudah menyediakan
  datanya.

---

## 3. Otorisasi laporan (matriks Bagian 2)

| Laporan | admin | kepala_sekolah | wakasek | guru | pegawai_struktural |
|---|:-:|:-:|:-:|:-:|:-:|
| Laporan presensi pegawai | K | L | L | L(S) | L(S) |
| Laporan jurnal & presensi siswa | K | L | L | L(S) | - |
| Rekap presensi siswa kelas wali | - | - | - | **L (kelas wali saja)** | - |

KP-5.5 menegaskan: **wali kelas hanya boleh melihat rekap kelasnya**, dan **guru hanya
jurnalnya sendiri**. Batasi di server, bukan di tampilan.

Catatan penting dari Fase 4: **kepala sekolah dan wakasek TIDAK punya akses ke endpoint
`/jurnal`** (mereka hanya `L` pada laporan). Jadi laporan Fase 5 memerlukan endpoint
laporan tersendiri — jangan longgarkan middleware `/jurnal` demi mereka.

---

## 4. Jebakan teknis yang sudah terbukti menggigit

Semuanya sudah pernah terjadi pada fase sebelumnya; baca sebelum menulis ekspor.

1. **Unduhan ber-token butuh `fetch` + blob, bukan `<a href>`.** Endpoint ekspor meminta
   header `Authorization`, sehingga tautan biasa balas 401. Pola pemakaiannya sudah ada di
   `src/lib/api.ts` (`urlFotoBerpelindung`).
2. **Jangan `ob_end_clean()` manual** sebelum `response()->download()`: tidak perlu dan
   membuat PHPUnit menandai uji *risky*.
3. **Validasi unggahan jangan mengandalkan `mimes:xlsx`** — xlsx adalah arsip ZIP sehingga
   berkas sah bisa ditolak. Validasi ekstensi nama berkas lalu muat dengan `IOFactory::load()`.
4. **Tanggal tanpa jam jangan lewat `new Date()`** di frontend — `'2026-07-06'` dianggap UTC
   dan harinya bergeser. Sudah ada `formatTanggalDari()` (lihat) dan `tanggalLokal()`
   (halaman jurnal) — keduanya ada karena bug ini pernah lolos di Windows dan baru
   ketahuan di CI Linux.
5. **`$collection[$kunci]` melempar galat** bila kuncinya tidak ada — pakai `->get()`.
   Sering muncul saat menyusun periode bulanan/mingguan.
6. **`getFillable()` sudah berupa daftar** — `array_keys($model->getFillable())` selalu salah.
7. **Jangan pakai `groupBy` pada kolom yang bukan kunci utama** dalam query laporan; untuk
   kelas & tahun, konvensi proyek memakai `DISTINCT`.
8. **Verifikasi memakai perintah kanonik tanpa pipa** (`php artisan test --compact`), dan
   **laporkan angka backend secara eksplisit** — suite PHP tidak pernah memenuhi penanda
   bukti kanonik walau lulus.

---

## 5. Keadaan terverifikasi saat serah terima

| | au-backend | au-frontend |
|---|---|---|
| HEAD | `50b8026` | `3b819c3` |
| Uji | **260 lulus / 988 assertion** | 34 lulus / 5 berkas |
| Pint / lint | PASS (263 berkas) | 0 masalah |
| Build | — | sukses, precache 1096,94 KiB |
| CI | success | success |

Rute API: **141**. Uji Fase 4: **41** (KP-4.1..KP-4.6 tertutup). Uji asap peramban Fase 4:
kartu "Jadwal Hari Ini" menampilkan 2 sesi dengan status Belum **tanpa** tombol Isi Jurnal
(gerbang BR-19 tersampaikan benar), halaman riwayat & rekap render tanpa galat konsol.

---

## 6. Utang teknis yang diwariskan

1. **Code-splitting belum dikerjakan.** Bundel utama kini ~794 kB (gzip ~225 kB) dan
   precache 1096,94 KiB. Catatan terukurnya ada di §7 serah-terima Fase 3 (arsip) dengan
   urutan perbaikan yang disarankan.
2. **`api.ts` diimpor dinamis sekaligus statis** — Vite memperingatkan impor dinamisnya
   tidak memecah bundel. Ini yang membuat pemecahan kode tidak berjalan; hilangkan dulu
   impor statisnya (lihat skill: "Impor statis + dinamis pada modul yang sama").
3. **Seed data belum punya jurnal**, sehingga halaman riwayat/rekap tampil kosong saat
   pertama dilihat. Untuk demo/laporan sebaiknya ditambah seeder jurnal.
4. **Foto jurnal** disimpan privat dan diambil sebagai blob; belum ada pembersihan berkas
   saat jurnal dihapus (retensi belum masuk cakupan fase ini).
