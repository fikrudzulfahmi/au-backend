# SIPANDU — Backend (au-backend)

REST API aplikasi **SIPANDU** (Sistem Presensi & Jurnal Digital) untuk
**SMK Islam Anharul Ulum**. Dokumen ini adalah panduan instalasi dan pengujian
untuk repo backend.

- Stack: **Laravel 12**, **PHP 8.3**, **MySQL/MariaDB 8** (`InnoDB`, `utf8mb4`), mode API saja.
- Autentikasi: **Laravel Sanctum personal access token (Bearer)** — repo & domain terpisah dari frontend (A-14).
- Dokumen spesifikasi: [`docs/spesifikasi-aplikasi-presensi-smk.md`](docs/spesifikasi-aplikasi-presensi-smk.md)
  (sumber kebenaran tunggal; Bagian 6 dan 7 bersifat mengikat).
- Catatan keputusan & penyimpangan: [`CATATAN-KEPUTUSAN.md`](CATATAN-KEPUTUSAN.md).

> Status: **Fase 0 selesai** (kerangka dua repo + autentikasi + waktu server + Info Sekolah).
> Rencana fase ada di Bagian 11 dokumen spesifikasi.

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

## 6. Endpoint yang sudah tersedia (Fase 0)

| Metode | Jalur | Akses | Keterangan |
|---|---|---|---|
| GET | `/api/v1/waktu-server` | publik | BR-13/BR-38 — jam acuan UI & layar TV |
| GET | `/api/v1/publik/sekolah` | publik | FR-SCH-02/BR-34 — Info Sekolah untuk landing |
| POST | `/api/v1/auth/login` | publik (5/menit/IP) | FR-SEC-01 — menerbitkan token Bearer |
| GET | `/api/v1/auth/me` | Bearer | data pengguna, peran, dan pegawai |
| POST | `/api/v1/auth/logout` | Bearer | mencabut token aktif |
| POST | `/api/v1/auth/ganti-password` | Bearer | FR-SEC-04 |

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
