# PANDUAN INSTALASI — SIPANDU (au-backend)

Dokumen ini menjelaskan cara memasang dan menjalankan **REST API SIPANDU**
(`au-backend`) — Laravel 12, mode API saja. Frontend (`au-frontend`,
Vite + React 18) dijalankan sebagai repo terpisah.

Sumber kebenaran: `README.md`, `.env.example`, `config/cors.php`,
`routes/console.php`, `phpunit.xml`, dan
`docs/spesifikasi-aplikasi-presensi-smk.md` (Bagian 3 & 4).

---

## 1. Ringkasan stack

| Komponen | Nilai |
|---|---|
| Kerangka | Laravel 12 (API-only) |
| PHP | 8.3 |
| Basis data | MySQL 8 / MariaDB 10.4+ (`InnoDB`, `utf8mb4`) |
| Autentikasi | Laravel Sanctum — personal access token (Bearer) |
| Zona waktu | `Asia/Jakarta` (waktu server otoritatif) |
| Dokumentasi API | OpenAPI otomatis (`dedoc/scramble`) di `/docs/api` |
| Pengujian | Pest 3.x + PHPUnit 11.5 |

Sumber: `README.md` §Stack, `CATATAN-KEPUTUSAN.md` (K-02, K-03).

---

## 2. Prasyarat

| Perangkat | Versi | Keterangan |
|---|---|---|
| PHP | 8.3+ | Ekstensi: `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sqlite3`, `zip`, `exif` |
| Composer | 2.7+ | |
| MySQL / MariaDB | 8 / 10.4+ | Basis data `sipandu` dan `sipandu_test` |
| Ekstensi gambar | GD + Intervention Image v3 | Untuk kompresi & watermark foto (BR-29) |

Sumber: `README.md` §1.

### Catatan lingkungan pengembangan (Windows + XAMPP)

XAMPP bawaan berisi PHP 8.2, sedangkan proyek memerlukan PHP 8.3. Agar proyek
lain tetap utuh, PHP 8.3 dipasang **terpisah**:

1. Unduh `php-8.3.x-nts-Win32-vs16-x64.zip` dari <https://windows.php.net/download/>.
2. Ekstrak ke `C:\php83`.
3. Jalankan `python tools/siapkan-php83.py` untuk membuat `C:\php83\php.ini`
   dengan seluruh ekstensi yang dibutuhkan aktif.
4. Verifikasi: `C:\php83\php.exe -v` dan `C:\php83\php.exe -m`.

Seluruh perintah artisan/composer kemudian dijalankan memakai `C:\php83\php.exe`.

Sumber: `README.md` §1, `CATATAN-KEPUTUSAN.md` K-01.

---

## 3. Langkah pemasangan

```bash
# 1. Dependensi PHP
composer install

# 2. Konfigurasi lingkungan
cp .env.example .env
php artisan key:generate

# 3. Siapkan basis data (sekali saja)
mysql -u root -e "CREATE DATABASE sipandu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE sipandu_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Migrasi + data contoh (Bagian 10 spesifikasi)
php artisan migrate --seed
```

> Di Windows + XAMPP, ganti `php` menjadi `C:\php83\php.exe`.

---

## 4. Konfigurasi `.env`

Salin dari `.env.example`. Variabel penting:

| Variabel | Contoh / bawaan | Keterangan |
|---|---|---|
| `APP_NAME` | `SIPANDU` | Nama aplikasi |
| `APP_ENV` | `local` | `production` di server |
| `APP_KEY` | — | Hasil `php artisan key:generate`; **rahasia**, jangan di-commit |
| `APP_URL` | `http://127.0.0.1:8000` | URL dasar backend |
| `APP_TIMEZONE` | `Asia/Jakarta` | BR-13/BR-38 — waktu server otoritatif |
| `APP_LOCALE` | `id` | Bahasa pesan |
| `FRONTEND_URL` | `http://localhost:5173,http://127.0.0.1:5173` | **Origin CORS yang diizinkan**, dipisah koma (K-13) |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` | `sipandu` | Basis data utama |
| `DB_USERNAME` / `DB_PASSWORD` | `root` / *(kosong)* | Kredensial basis data |
| `DB_DATABASE_TEST` | `sipandu_test` | Dipakai oleh `phpunit.xml` |
| `FILESYSTEM_DISK` | `local` | Disk privat (`storage/app/private`) untuk foto (K-36, K-69) |
| `CACHE_STORE` | `database` | Produksi; uji memakai `array` |
| `SESSION_DRIVER` | `database` | |
| `SANCTUM_TOKEN_EXPIRATION` | *(kosong)* | Kosong = token tidak kedaluwarsa (dicabut saat logout) |
| `QUEUE_CONNECTION` | `database` | |

**Penting untuk CORS:** hanya origin yang tercantum di `FRONTEND_URL` yang
diizinkan. Setel ke alamat asli frontend saat produksi (mis. `https://app.domain`).
Sumber: `.env.example`, `config/cors.php` (3.3), K-13.

---

## 5. Migrasi & seeder

```bash
php artisan migrate --seed          # sekali, saat pemasangan
php artisan migrate:fresh --seed    # bangun ulang dari nol (hati-hati: menghapus data)
```

Akun contoh hasil seeder (Bagian 10) — **password awal wajib diganti** saat login
pertama (FR-SEC-04). Password awal: `Sipandu#2026`
(`database/seeders/UserSeeder.php`, konstanta `PASSWORD_AWAL`).

| Peran | Username |
|---|---|
| Administrator (tanpa data pegawai) | `admin` |
| Kepala Sekolah | NIP `198304202009011003` |
| Wakasek Kurikulum (sekaligus guru) | NIP `198507152010012004` |
| Guru / pegawai lain | sesuai NIP masing-masing |

> Ganti password contoh ini sebelum dipakai di produksi.

---

## 6. Menjalankan server

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend (repo `au-frontend`) dijalankan terpisah pada port `5173`.

Pemeriksaan cepat:

```bash
curl http://127.0.0.1:8000/api/v1/waktu-server
curl http://127.0.0.1:8000/api/v1/publik/sekolah
```

Dokumentasi API (OpenAPI): <http://127.0.0.1:8000/docs/api>.

---

## 7. Menjalankan pengujian

Uji memakai **Pest** dan basis data terpisah `sipandu_test` (dikonfigurasi di
`phpunit.xml`, `DB_DATABASE` dengan `force="true"`), sehingga data pengembangan
tidak tersentuh.

```bash
php artisan test          # atau: vendor/bin/pest
vendor/bin/pint --test    # pemeriksaan gaya kode
```

Catatan: `phpunit.xml` memaksa `CACHE_STORE=array` dan `DB_DATABASE=sipandu_test`.
Variabel lingkungan biasa **tidak** dapat menimpanya selama `force="true"`.
Sumber: `phpunit.xml`, `README.md` §4.

---

## 8. Catatan produksi (cPanel)

1. **HTTPS wajib.** Kamera (`getUserMedia`) dan GPS (`navigator.geolocation`)
   hanya berjalan pada origin aman. Sumber: FR-SEC-07, README §7.
2. **Dua subdomain terpisah**, mis. `api.<domain>` (backend) dan `app.<domain>`
   (frontend), keduanya HTTPS. Sumber: spesifikasi 3.3.
3. **Arahkan document root ke folder `public/`** backend, then setel
   `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`.
4. **Setel `FRONTEND_URL`** ke origin frontend produksi (bukan `localhost`).
5. **Mesin basis data** disetel `InnoDB` + `Schema::defaultStringLength(191)`
   untuk mencegah galat indeks terlalu panjang di host yang ragu.
   Sumber: K-22.
6. **Izin tulis** untuk `storage/` dan `bootstrap/cache/`. Foto disimpan di
   `storage/app/private` (disk `local`, tidak publik). Sumber:
   `config/filesystems.php`, K-36.
7. **Penjadwalan retensi foto (BR-30):** daftarkan cron agar Laravel scheduler
   berjalan tiap menit, sehingga perintah `presensi:bersihkan-foto` tereksekusi
   harian pukul **01:30 Asia/Jakarta**:

   ```cron
   * * * * * cd /path/ke/au-backend && php artisan schedule:run >> /dev/null 2>&1
   ```

   Definisi jadwal: `routes/console.php` (K-92). Perintahnya idempoten — aman
   dijalankan berulang. Sumber: `routes/console.php`, `app/Console/Commands/BersihkanFotoPresensi.php`.

8. **Jalankan migrasi produksi** dengan `php artisan migrate --force`.
9. **Jangan commit `.env`.** `.env` diabaikan git (`.gitignore`); gunakan
   `.env.example` sebagai acuan. Sumber: README §7.
10. **Batasan fake GPS** wajib dipahami pengelola — lihat
    [`BATASAN-DETEKSI-FAKE-GPS.md`](BATASAN-DETEKSI-FAKE-GPS.md) (FR-SEC-08).

---

## 9. Perintah artisan yang relevan

| Perintah | Guna |
|---|---|
| `php artisan migrate --seed` | Bangun skema + data contoh |
| `php artisan serve` | Jalankan server pengembangan |
| `php artisan test` | Jalankan suite Pest |
| `php artisan route:list` | Daftar seluruh endpoint |
| `php artisan schedule:list` | Lihat tugas terjadwal |
| `php artisan presensi:bersihkan-foto --kering` | **Laporkan** berkas yang akan dihapus (tidak menghapus) |
| `php artisan presensi:bersihkan-foto` | Hapus berkas foto tahun pelajaran berstatus `selesai` (BR-30) |

Opsi `presensi:bersihkan-foto`: `--tahun-pelajaran=<nama|id>`, `--kering`,
`--dry-run`. Sumber: `app/Console/Commands/BersihkanFotoPresensi.php`.
