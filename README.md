# SIPANDU — Backend (au-backend)

REST API aplikasi **SIPANDU** (Sistem Presensi & Jurnal Digital) untuk
**SMK Islam Anharul Ulum**. Dokumen ini adalah panduan instalasi dan pengujian
untuk repo backend.

- Stack: **Laravel 12**, **PHP 8.3**, **MySQL/MariaDB 8** (`InnoDB`, `utf8mb4`), mode API saja.
- Autentikasi: **Laravel Sanctum personal access token (Bearer)** — repo & domain terpisah dari frontend (A-14).
- Dokumen spesifikasi: [`docs/spesifikasi-aplikasi-presensi-smk.md`](docs/spesifikasi-aplikasi-presensi-smk.md)
  (sumber kebenaran tunggal; Bagian 6 dan 7 bersifat mengikat).
- Catatan keputusan & penyimpangan: [`CATATAN-KEPUTUSAN.md`](CATATAN-KEPUTUSAN.md).

> Status: **Fase 0, 1, 2, 3, dan 4 selesai.**
> Fase 0 — kerangka dua repo, autentikasi, waktu server, Info Sekolah.
> Fase 1 — master data (tahun pelajaran/semester/hari libur, jurusan, kelas, siswa, pegawai, mapel),
> import/export Excel, pengaturan sistem, pengguna & peran, audit log.
> Fase 2 — plotting kelas (termasuk wizard naik kelas & kelulusan serta mutasi),
> plotting mapel, pola jam pelajaran, dan jadwal dengan penolakan bentrok.
> Fase 3 — presensi GPS + foto dengan watermark, luar radius dua jalur, pengajuan
> izin/sakit/dinas/cuti, monitoring harian, persetujuan, serta pengaturan lokasi & jam kerja.
> **219 uji Pest lulus (855 assertion).** Rencana fase ada di Bagian 11 dokumen spesifikasi.
> Fase 4 — jurnal pembelajaran dan presensi siswa: sesi terbentuk dari jadwal (entri
> berurutan digabung jadi satu sesi), gerbang presensi masuk (BR-19), hari izin disetujui
> tampil "Berhalangan" dan tidak dapat diisi (KP-4.6), edit tanpa batas waktu dengan jejak
> audit (BR-20), serta rekap presensi siswa untuk wali kelas.
>
> Untuk melanjutkan ke Fase 5 (laporan & dokumen resmi), baca
> `docs/SERAH-TERIMA-FASE-5.md`.

---

## Dokumentasi

| Dokumen | Isi |
|---|---|
| [`docs/PANDUAN-INSTALASI.md`](docs/PANDUAN-INSTALASI.md) | Pemasangan, konfigurasi `.env`, migrasi/seeder, menjalankan server & uji, catatan produksi cPanel |
| [`docs/PANDUAN-PENGGUNA-ADMIN.md`](docs/PANDUAN-PENGGUNA-ADMIN.md) | Master data, plotting & jadwal, lokasi/jam kerja, kop & TTD, pengumuman, layar TV, monitoring, laporan, audit log |
| [`docs/PANDUAN-PENGGUNA-GURU.md`](docs/PANDUAN-PENGGUNA-GURU.md) | Presensi masuk/pulang, pengajuan izin/dinas, jurnal + presensi siswa, rekap kelas wali |
| [`docs/PANDUAN-PENGGUNA-PEGAWAI.md`](docs/PANDUAN-PENGGUNA-PEGAWAI.md) | Presensi harian, pengajuan, riwayat untuk peran pegawai struktural |
| [`docs/PANDUAN-BACKUP.md`](docs/PANDUAN-BACKUP.md) | Apa yang dicadangkan, cara mencadangkan & memulihkan, hubungannya dengan retensi foto (BR-30) |
| [`docs/BATASAN-DETEKSI-FAKE-GPS.md`](docs/BATASAN-DETEKSI-FAKE-GPS.md) | Batasan deteksi fake GPS (FR-SEC-08) yang harus dipahami pengelola |
| [`CATATAN-KEPUTUSAN.md`](CATATAN-KEPUTUSAN.md) | Keputusan & penyimpangan dari spesifikasi |

---

## 1. Prasyarat

| Perangkat | Versi | Keterangan |
|---|---|---|
| PHP | 8.3+ | Wajib. Ekstensi: `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sqlite3`, `zip`, `exif` |
| Composer | 2.7+ | |
| MySQL / MariaDB | 8 / 10.4+ | Basis data `sipandu` dan `sipandu_test` |

### Catatan lingkungan pengembangan (Windows + XAMPP)

XAMPP bawaan proyek ini berisi PHP 8.2, sedangkan spesifikasi meminta PHP 8.3.
Agar proyek lain yang memakai PHP 8.2 tidak terganggu, PHP 8.3 dipasang terpisah:

1. Unduh `php-8.3.x-nts-Win32-vs16-x64.zip` dari <https://windows.php.net/download/>.
2. Ekstrak ke `C:\php83`.
3. Jalankan `python tools/siapkan-php83.py` untuk membuat `C:\php83\php.ini`
   (mengaktifkan seluruh ekstensi yang dibutuhkan).
4. Verifikasi: `C:\php83\php.exe -v` dan `C:\php83\php.exe -m`.

Seluruh perintah artisan/composer pada repo ini kemudian dijalankan memakai
`C:\php83\php.exe`.

---

## 2. Instalasi

```bash
# 1. Dependensi PHP
composer install

# 2. Konfigurasi lingkungan
cp .env.example .env
php artisan key:generate

# 3. Siapkan basis data (sekali saja)
mysql -u root -e "CREATE DATABASE sipandu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE sipandu_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Migrasi + data contoh (Bagian 10)
php artisan migrate --seed
```

`APP_TIMEZONE` bawaan `Asia/Jakarta`. `FRONTEND_URL` dipakai untuk CORS dan
menerima beberapa origin dipisah koma.

---

## 3. Menjalankan

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend (repo `au-frontend`) dijalankan terpisah pada port 5173.

Pemeriksaan cepat:

```bash
curl http://127.0.0.1:8000/api/v1/waktu-server
curl http://127.0.0.1:8000/api/v1/publik/sekolah
```

Dokumentasi API (OpenAPI otomatis, `dedoc/scramble`): <http://127.0.0.1:8000/docs/api>

---

## 4. Menguji

Uji memakai **Pest** dan database terpisah `sipandu_test`
(dikonfigurasi di `phpunit.xml`), sehingga data pengembangan tidak tersentuh.

```bash
php artisan test          # atau: vendor/bin/pest
vendor/bin/pint --test    # pemeriksaan gaya kode
```

Data akun contoh (Bagian 10) — **wajib diganti saat login pertama** (FR-SEC-04):

| Peran | Username | Password awal |
|---|---|---|
| Administrator (tanpa data pegawai) | `admin` | `Sipandu#2026` |
| Kepala Sekolah | NIP `198304202009011003` | `Sipandu#2026` |
| Wakasek Kurikulum (sekaligus guru) | NIP `198507152010012004` | `Sipandu#2026` |
| Guru / pegawai lain | sesuai NIP masing-masing | `Sipandu#2026` |

> Ganti password contoh ini sebelum dipakai di produksi.

---

## 5. Struktur kode

```
app/
├── Http/
│   ├── Controllers/Api/V1/   AuthController, WaktuServerController, PublikSekolahController
│   ├── Middleware/           PastikanPeran (otorisasi peran), CatatPermintaanApi
│   ├── Requests/             LoginRequest, GantiPasswordRequest
│   └── Resources/            PenggunaResource, SekolahResource
├── Models/                   User, Pegawai, Role, ProfilSekolah, Pengaturan, AuditLog, ...
└── Services/                 PengaturanService, AuditLogService, WaktuService
database/
├── migrations/               lihat 7.1 dan 7.6 dokumen spesifikasi
├── seeders/                  RoleSeeder, PegawaiSeeder, UserSeeder, InfoSekolahSeeder, ...
└── factories/
docs/                         dokumen spesifikasi
routes/api.php                 seluruh rute /api/v1
tests/Feature, tests/Unit      uji Pest
```

---

## 6. Endpoint yang sudah tersedia

**Publik & autentikasi (Fase 0)**

| Metode | Jalur | Akses | Keterangan |
|---|---|---|---|
| GET | `/api/v1/waktu-server` | publik | BR-13/BR-38 — jam acuan UI & layar TV |
| GET | `/api/v1/publik/sekolah` | publik | FR-SCH-02/BR-34 — Info Sekolah untuk landing |
| POST | `/api/v1/auth/login` | publik (5/menit/IP) | FR-SEC-01 — menerbitkan token Bearer |
| GET | `/api/v1/auth/me` | Bearer | data pengguna, peran, dan pegawai |
| POST | `/api/v1/auth/logout` | Bearer | mencabut token aktif |
| POST | `/api/v1/auth/ganti-password` | Bearer | FR-SEC-04 |

**Master data (Fase 1)** — `L` = admin, kepala sekolah, wakasek; `K` = admin saja.

| Metode | Jalur | Akses | Keterangan |
|---|---|---|---|
| GET | `/api/v1/tahun-pelajaran` · `/{id}` | L | FR-TP-01 |
| POST/PUT/DELETE | `/api/v1/tahun-pelajaran` · `/{id}` | K | FR-TP-01 |
| POST | `/api/v1/tahun-pelajaran/{id}/aktifkan` | K | FR-TP-04 / BR-01 |
| POST | `/api/v1/tahun-pelajaran/{id}/selesai` | K | FR-TP-05 / BR-27 |
| POST | `/api/v1/tahun-pelajaran/{id}/salin` | K | FR-TP-06 (ringkasan) |
| PUT | `/api/v1/semester/{id}` | K | FR-TP-02 |
| GET/POST/PUT/DELETE | `/api/v1/hari-libur` | L / K | FR-TP-07 |
| GET | `/api/v1/jurusan` · `/kelas` · `/mapel` | L | FR-KLS, FR-MPL |
| POST/PUT/DELETE | `/api/v1/jurusan` · `/kelas` · `/mapel` | K | BR-02 pada kelas |
| POST | `/api/v1/kelas/salin` | K | FR-KLS-04 (X→XI, XI→XII) |
| GET | `/api/v1/siswa` · `/siswa/{id}` | L | FR-SIS-01/05 |
| POST/PUT/DELETE | `/api/v1/siswa` · `/siswa/{id}` | K | FR-SIS-01/02 |
| GET | `/api/v1/siswa/template` · `/siswa/ekspor` | K | FR-SIS-03/04 |
| POST | `/api/v1/siswa/import` | K | FR-SIS-03 / KP-1.3 |
| GET | `/api/v1/pegawai` · `/pegawai/{id}` | L | FR-PEG-01 |
| POST/PUT/DELETE | `/api/v1/pegawai` · `/pegawai/{id}` | K | FR-PEG-01 / KP-1.4 |
| POST | `/api/v1/pegawai/{id}/akun` · `/reset-password` · `/reset-perangkat` | K | FR-PEG-02/05/06 |
| GET | `/api/v1/pegawai/template` · `/pegawai/ekspor` | K | FR-PEG-03 |
| POST | `/api/v1/pegawai/import` | K | FR-PEG-03 / KP-1.3 |
| GET | `/api/v1/mapel/ekspor` | K | FR-MPL-02 |

**Plotting & akademik (Fase 2)** — `L` = admin, kepala sekolah, wakasek; `K` = admin
(plotting kelas) atau admin + wakasek (plotting mapel, jam, jadwal); `S` = data sendiri (guru).

| Metode | Jalur | Akses | Keterangan |
|---|---|---|---|
| GET | `/api/v1/plotting-kelas` · `/ringkasan` · `/belum-terplot` | L | FR-PLK-08 |
| GET | `/api/v1/plotting-kelas/ekspor` | L | FR-PLK-09 |
| GET | `/api/v1/siswa/{id}/riwayat-kelas` | L | FR-PLK-08 |
| POST | `/api/v1/plotting-kelas` | K | FR-PLK-01 (dapat massal) |
| POST | `/api/v1/plotting-kelas/import` | K | FR-PLK-01 (NIS + nama kelas) |
| POST | `/api/v1/plotting-kelas/{id}/mutasi` | K | FR-PLK-05 (alasan wajib) |
| POST | `/api/v1/plotting-kelas/{id}/batalkan` | K | FR-PLK-06 |
| DELETE | `/api/v1/plotting-kelas/{id}` | K | Keluarkan siswa dari kelas |
| POST | `/api/v1/plotting-kelas/wizard/pratinjau` | K | FR-PLK-02 (status bawaan + saran kelas) |
| POST | `/api/v1/plotting-kelas/wizard/eksekusi` | K | FR-PLK-02/03 (transaksional, idempotent) |
| GET | `/api/v1/plotting-mapel` · `/matriks` · `/per-guru` · `/ekspor` | L / S | FR-PLM-04 |
| POST/PUT/DELETE | `/api/v1/plotting-mapel` · `/{id}` | K | FR-PLM-01..03, BR-03 |
| POST | `/api/v1/plotting-mapel/salin` | K | FR-PLM-05 |
| GET | `/api/v1/jam-pelajaran` | L | FR-JAM-01 |
| POST/PUT/DELETE | `/api/v1/jam-pelajaran` · `/{id}` · `/slot/{id}` | K | FR-JAM-02..05, BR-05 |
| POST | `/api/v1/jam-pelajaran/salin` | K | FR-JAM-04 |
| GET | `/api/v1/jadwal` · `/peringatan` · `/ekspor` | L / S | FR-JDW-04/05/07 |
| GET | `/api/v1/jadwal/hari-ini` · `/mingguan` | semua peran | FR-JDW-06 (jadwal milik sendiri) |
| POST/DELETE | `/api/v1/jadwal` · `/{id}` | K | FR-JDW-01..03, BR-06/07/08/09 |

**Presensi pegawai (Fase 3)** — presensi untuk pegawai yang bersangkutan (`/presensi/*`);
pengajuan dapat dibuat pegawai, diputuskan admin/kepala sekolah (`FR-IZN-04`); monitoring
dapat dilihat admin/kepala sekolah/wakasek dan diputuskan admin/kepala sekolah.

| Metode | Jalur | Akses | Keterangan |
|---|---|---|---|
| GET | `/api/v1/presensi/hari-ini` | pegawai | Status hari ini + daftar lokasi efektif (BR-12) |
| POST | `/api/v1/presensi/masuk` · `/pulang` | pegawai | FR-PRS-01/08, BR-10/15/16/17/29 |
| GET | `/api/v1/presensi/riwayat` | pegawai | FR-PRS-12 |
| GET | `/api/v1/presensi/{id}/foto/{sisi}` | pemilik / pemantau | Foto dari disk privat |
| GET/POST | `/api/v1/pengajuan-izin` | pegawai / pemantau | FR-IZN-01/09 |
| PATCH | `/api/v1/pengajuan-izin/{id}/putuskan` | admin, kepala sekolah | FR-IZN-04 |
| PATCH | `/api/v1/pengajuan-izin/{id}/batalkan` | pemilik / admin | FR-IZN-03 |
| POST | `/api/v1/pengajuan-izin/atas-nama/{pegawai}` | admin | FR-IZN-08 |
| GET/POST | `/api/v1/pengajuan-luar-radius` | pegawai / pemantau | FR-IZN-06 |
| PATCH | `/api/v1/pengajuan-luar-radius/{id}/putuskan` | admin, kepala sekolah | FR-PRS-11 |
| GET | `/api/v1/monitoring/presensi-harian` · `/{id}` · `/persetujuan-presensi` | pemantau | FR-PRS-10/11 |
| PATCH | `/api/v1/monitoring/presensi-harian/{id}/putuskan` | admin, kepala sekolah | FR-PRS-11 (BR-18) |
| PATCH | `/api/v1/monitoring/presensi-harian/{id}/koreksi` | admin, kepala sekolah | FR-PRS-13 |
| POST | `/api/v1/monitoring/persetujuan-presensi/massal` | admin, kepala sekolah | Aksi massal |
| GET/POST/PUT/DELETE | `/api/v1/pengaturan/lokasi` · `/{id}` · `/{id}/default` · `/tetapkan` · `/pegawai` | admin | FR-LOK-01..03 (BR-12) |
| GET/POST | `/api/v1/jam-kerja` | admin | FR-LOK-04, BR-15/16/24 |

**Pengaturan (Fase 1)** — hanya admin (`FR-SCH-06`).

| Metode | Jalur | Keterangan |
|---|---|---|
| GET/POST | `/api/v1/pengaturan/sekolah` | Info Sekolah; NPSN wajib 8 digit, logo dikompres (KP-1.6) |
| POST | `/api/v1/pengaturan/sekolah/penandatangan-default` | FR-SCH-03 |
| GET/PUT | `/api/v1/pengaturan/landing` | FR-SCH-05 |
| GET/PUT | `/api/v1/pengaturan/sistem` | FR-LOK-05 (akurasi GPS, kompresi foto) |
| GET | `/api/v1/pengaturan/audit-log` · `/aksi` | FR-SEC-05 — hanya baca |
| GET/PUT | `/api/v1/pengaturan/pengguna` · `/{id}` | Bagian 2 / A-11 |
| POST | `/api/v1/pengaturan/pengguna/{id}/reset-perangkat` | BR-14 |

Konvensi respons (3.4):

```json
{ "data": { } }
{ "data": [ ], "meta": { "page": 1, "per_page": 20, "total": 0 } }
{ "message": "…", "code": "KODE_KESALAHAN" }
{ "message": "Data yang dikirim tidak valid.", "errors": { "field": ["…"] } }
```

Header yang dipakai: `Authorization: Bearer <token>`, `Accept: application/json`,
`X-Device-Token: <token perangkat>` (pengikatan perangkat/BR-14 menyusul pada Fase 3).

---

## 7. Keamanan

- Seluruh otorisasi dicek di **server** (middleware `peran`, Form Request, Policy/Gate);
  menyembunyikan menu di frontend bukan pengganti otorisasi.
- Tidak ada rahasia yang di-commit. `.env` diabaikan git; gunakan `.env.example`.
- Login dibatasi 5 percobaan per menit per IP; percobaan gagal dicatat di `audit_log`.
- HTTPS wajib di produksi (kamera & GPS memerlukannya).
- Deteksi *fake GPS* tidak dapat sempurna pada aplikasi web — mitigasinya:
  akurasi GPS, foto kamera langsung, pengikatan perangkat, watermark, dan
  tinjauan admin atas presensi luar radius (FR-SEC-08).

---

## 8. Lisensi

MIT. Lihat `composer.json`.
