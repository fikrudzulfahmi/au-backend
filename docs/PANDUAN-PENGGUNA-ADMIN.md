# PANDUAN PENGGUNA — ADMIN

Panduan ini untuk pengguna dengan peran **`admin`** (operator/super admin).
Admin mengelola seluruh master data, pengaturan, plotting/jadwal, persetujuan,
pengumuman, dan layar TV. Admin **boleh tidak terhubung** ke data pegawai.

Sumber: spesifikasi Bagian 2 (matriks akses), Bagian 8 (navigasi), dan
`routes/api.php`.

> Konvensi: seluruh alur memakai API `/api/v1` dengan header
> `Authorization: Bearer <token>`. Hak akses selalu diperiksa **di server**;
> menu yang tampak di layar bukan pengganti otorisasi.

---

## 1. Masuk dan keamanan akun

1. Buka aplikasi, masukkan **username** dan **password** (`POST /api/v1/auth/login`).
   Login dibatasi **5 percobaan/menit/IP**; percobaan gagal dicatat di `audit_log`.
2. Password awal contoh (`Sipandu#2026`) **wajib diganti** pada login pertama
   (`POST /api/v1/auth/ganti-password`, FR-SEC-04).
3. Akun `admin` yang tidak terhubung pegawai **dikecualikan** dari pengikatan
   perangkat (FR-SEC-03).

Lihat status akun Anda: `GET /api/v1/auth/me`. Keluar: `POST /api/v1/auth/logout`.

---

## 2. Master data

Menu master data mencakup: tahun pelajaran & semester, hari libur, jurusan,
kelas, siswa, pegawai, dan mata pelajaran. Akses: **K** = admin
(tambah/ubah/hapus); kepala sekolah & wakasek hanya **L** (lihat).

### 2.1 Tahun pelajaran & semester

| Aksi | Endpoint |
|---|---|
| Daftar / detail | `GET /api/v1/tahun-pelajaran`, `GET /api/v1/tahun-pelajaran/{id}` |
| Tambah / ubah / hapus | `POST` · `PUT/PATCH` · `DELETE /api/v1/tahun-pelajaran[/{id}]` |
| **Aktifkan** | `POST /api/v1/tahun-pelajaran/{id}/aktifkan` (BR-01: hanya satu aktif) |
| **Tandai Selesai** | `POST /api/v1/tahun-pelajaran/{id}/selesai` (BR-27, memicu BR-30) |
| Salin (ringkasan) | `POST /api/v1/tahun-pelajaran/{id}/salin` |
| Ubah semester | `PUT/PATCH /api/v1/semester/{id}` |

> **Tandai Selesai** membuat tahun pelajaran menjadi `selesai` (read-only untuk
> data transaksi) dan **memicu aturan retensi foto** (`FR-TP-05`, BR-30).
> Tindakan ini destruktif jangka panjang — baca
> [`PANDUAN-BACKUP.md`](PANDUAN-BACKUP.md) sebelum melakukannya.

### 2.2 Hari libur

`GET/POST/PUT/DELETE /api/v1/hari-libur[/{id}]`. Hari libur memengaruhi hitungan
alpa dan "hari kerja" (BR-24/BR-25).

### 2.3 Jurusan, kelas, mapel

- Jurusan: `GET/POST/PUT/DELETE /api/v1/jurusan[/{id}]`.
- Kelas: `GET/POST/PUT/DELETE /api/v1/kelas[/{id}]`; salin kelas antar tingkat:
  `POST /api/v1/kelas/salin` (FR-KLS-04: X→XI, XI→XII). Penghapusan kelas
  dilindungi penjaga referensi (K-17).
- Mapel: `GET/POST/PUT/DELETE /api/v1/mapel[/{id}]`; ekspor: `GET /api/v1/mapel/ekspor`.

### 2.4 Siswa

| Aksi | Endpoint |
|---|---|
| Daftar (cari NIS/NISN/nama, status, tahun masuk) | `GET /api/v1/siswa` |
| Detail | `GET /api/v1/siswa/{id}` |
| Tambah / ubah / hapus | `POST` · `PUT` · `DELETE /api/v1/siswa[/{id}]` |
| Unduh template import | `GET /api/v1/siswa/template` |
| Ekspor | `GET /api/v1/siswa/ekspor` |
| Import | `POST /api/v1/siswa/import` |

### 2.5 Pegawai

| Aksi | Endpoint |
|---|---|
| Daftar / detail | `GET /api/v1/pegawai`, `GET /api/v1/pegawai/{id}` |
| Tambah / ubah / hapus | `POST` · `PUT` · `DELETE /api/v1/pegawai[/{id}]` |
| Buat akun | `POST /api/v1/pegawai/{id}/akun` (FR-PEG-02) |
| Reset password | `POST /api/v1/pegawai/{id}/reset-password` |
| Reset perangkat | `POST /api/v1/pegawai/{id}/reset-perangkat` (BR-14) |
| Template / ekspor / import | `GET /template` · `GET /ekspor` · `POST /import` |

**Penting soal password awal (K-20):** password awal akun pegawai dibuat
**acak** dan **hanya ditampilkan sekali** pada respons pembuatan/reset. Catat
dan sampaikan ke pegawai; sistem tidak pernah menampilkannya lagi di daftar.

**Penetapan lokasi presensi per pegawai** dilakukan di sini (FR-PEG-04),
mendukung penetapan massal (`POST /api/v1/pengaturan/lokasi/tetapkan`).

---

## 3. Plotting kelas & mapel

### 3.1 Plotting kelas (siswa ke kelas per tahun pelajaran)

- Lihat ringkasan & siswa belum terplot:
  `GET /api/v1/plotting-kelas/ringkasan`, `GET /api/v1/plotting-kelas/belum-terplot`.
- Tambah (dapat massal) / impor / keluarkan:
  `POST /api/v1/plotting-kelas`, `POST /api/v1/plotting-kelas/import`,
  `DELETE /api/v1/plotting-kelas/{id}`.
- Ekspor: `GET /api/v1/plotting-kelas/ekspor`.
- Riwayat kelas siswa: `GET /api/v1/siswa/{id}/riwayat-kelas`.

**Wizard naik kelas & kelulusan** (FR-PLK-02/03):
1. `POST /api/v1/plotting-kelas/wizard/pratinjau` — server menghitung status
   akhir bawaan (X/XI → naik kelas, XII → lulus) dan saran kelas tujuan (K-27).
2. Periksa pratinjau.
3. `POST /api/v1/plotting-kelas/wizard/eksekusi` — transaksional & idempoten
   (K-28): siswa yang sudah diproses dilewati dan dilaporkan, tidak digandakan.

**Mutasi** (pindah siswa antar kelas): `POST /api/v1/plotting-kelas/{id}/mutasi`
(alasan wajib, FR-PLK-05). **Batalkan naik kelas**: `POST .../{id}/batalkan`.

### 3.2 Plotting mapel (guru–mapel–kelas per semester)

- Lihat: `GET /api/v1/plotting-mapel`, `/matriks`, `/per-guru`, `/ekspor`.
- Kelola (admin & wakasek): `POST` · `PUT` · `DELETE /api/v1/plotting-mapel[/{id}]`.
- Salin: `POST /api/v1/plotting-mapel/salin`.

> Mengubah guru pengampu plotting **ikut memperbarui** jadwal terkait, agar
> pengecekan bentrok tetap benar (K-29).

---

## 4. Jam pelajaran & jadwal

### 4.1 Jam pelajaran (pola jam)

- Lihat: `GET /api/v1/jam-pelajaran`.
- Kelola: `POST/PUT/DELETE /api/v1/jam-pelajaran[...]`, termasuk slot
  (`.../{polaJam}/slot`, `.../slot/{slotJam}`).
- Salin: `POST /api/v1/jam-pelajaran/salin`.

### 4.2 Jadwal pelajaran

- Lihat: `GET /api/v1/jadwal`, `GET /api/v1/jadwal/peringatan`, dan ekspor
  `GET /api/v1/jadwal/ekspor`.
- Tambah / hapus: `POST /api/v1/jadwal`, `DELETE /api/v1/jadwal/{id}`.

Server **menolak bentrok** (BR-06/07/08/09) dengan pesan yang menyebut pelakunya;
aturan ditegakkan berlapis (validasi aplikasi + indeks unik DB, K-26).

---

## 5. Lokasi presensi & jam kerja

### 5.1 Lokasi presensi (FR-LOK-01..03)

| Aksi | Endpoint |
|---|---|
| Daftar / tambah | `GET /api/v1/pengaturan/lokasi`, `POST /api/v1/pengaturan/lokasi` |
| Ubah / hapus | `PUT` · `DELETE /api/v1/pengaturan/lokasi/{id}` |
| Jadikan default | `PATCH /api/v1/pengaturan/lokasi/{id}/default` |
| Tetapkan (massal) ke pegawai | `POST /api/v1/pengaturan/lokasi/tetapkan` |
| Lihat lokasi pegawai | `GET /api/v1/pengaturan/lokasi/pegawai` |

Setiap lokasi memiliki nama, latitude, longitude, **radius (meter)**, status
aktif, dan penanda default. **Hanya satu lokasi default** (BR-12; ditegakkan
database, K-34). **Radius tidak di-hardcode** — dapat diubah tanpa deploy
ulang (FR-LOK-06).

### 5.2 Jam kerja (FR-LOK-04)

- Lihat: `GET /api/v1/jam-kerja`; simpan: `POST /api/v1/jam-kerja`.
- Diatur **per `jenis_pegawai` dan per hari**: hari kerja (ya/tidak),
  `buka_presensi`, `jam_masuk`, `jam_pulang`.
- Jam masuk/pulang menjadi acuan status `terlambat` (BR-15, tanpa toleransi)
  dan `pulang_cepat` (BR-16).

### 5.3 Pengaturan teknis (FR-LOK-05)

`GET/PUT /api/v1/pengaturan/sistem` — antara lain `gps_max_akurasi_m` (default 50),
`foto_max_sisi_px` (800), `foto_kualitas_jpeg` (65), `foto_target_maks_kb` (150).

---

## 6. Info Sekolah, kop & tanda tangan, landing

| Fitur | Endpoint | Akses |
|---|---|---|
| Info Sekolah | `GET/POST /api/v1/pengaturan/sekolah` | admin |
| Jadikan penandatangan default | `POST /api/v1/pengaturan/sekolah/penandatangan-default` | admin |
| Tab Landing Page | `GET/PUT /api/v1/pengaturan/landing` | admin |
| Kop & TTD | `GET/PUT /api/v1/pengaturan/ttd` | admin |
| Pratinjau kop | `POST /api/v1/pengaturan/kop/pratinjau` | admin |
| Penandatangan | `GET/POST/PUT/DELETE /api/v1/pengaturan/penandatangan[/{id}]` | admin |

- **Info Sekolah** adalah sumber data untuk kop laporan, landing, layar TV,
  judul/ikon aplikasi, dan footer (FR-SCH-02). NPSN wajib 8 digit (FR-SCH-04);
  logo dikompres otomatis.
- **Kop surat** menambah baris teks, alamat/kontak, dan posisi logo
  (FR-KOP-02). **Penandatangan** memuat jabatan, nama, NIP, gambar TTD & stempel
  (maksimal 2 per dokumen, FR-KOP-03); tata letak (kota/tanggal, posisi,
  "Mengetahui") diatur di tab TTD (FR-KOP-04). Gunakan **Pratinjau** sebelum
  menyimpan (FR-KOP-05).
- Semua perubahan dicatat di `audit_log` (FR-SCH-06).

---

## 7. Pengguna, peran & reset perangkat

| Aksi | Endpoint |
|---|---|
| Daftar pengguna | `GET /api/v1/pengaturan/pengguna` |
| Daftar peran | `GET /api/v1/pengaturan/pengguna/peran` |
| Ubah pengguna/peran | `PUT /api/v1/pengaturan/pengguna/{id}` |
| Reset perangkat | `POST /api/v1/pengaturan/pengguna/{id}/reset-perangkat` |

Reset perangkat (BR-14) mengosongkan perangkat terdaftar agar pegawai dapat
login dari perangkat baru; pendaftaran ulang terjadi pada login berikutnya.

---

## 8. Pengumuman

Dikelola oleh **admin dan kepala sekolah** (FR-PMN-02).

| Aksi | Endpoint |
|---|---|
| Daftar (kelola) | `GET /api/v1/pengumuman` |
| Tambah / ubah / hapus | `POST` · `PUT/PATCH` · `DELETE /api/v1/pengumuman[/{id}]` |

Bidang: judul, isi, **tipe** (`pengumuman` | `pengingat` | `teks_berjalan`),
prioritas (`normal` | `penting`), rentang tanggal, jam tayang (opsional),
**target tampil** (aplikasi / TV / landing), gambar (opsional, dikompres),
status aktif (FR-PMN-01).

Aturan tayang (BR-36, K-75): pengumuman tampil hanya bila **aktif DAN** sekarang
berada dalam rentang tanggal (dan jam) **DAN** target tampil cocok. Pengumuman
kedaluwarsa **berhenti tampil tetapi tidak dihapus** (FR-PMN-03).

---

## 9. Layar TV (pengaturan `/pengaturan/tv`)

Hanya admin (FR-TV-16).

| Aksi | Endpoint |
|---|---|
| Lihat / simpan pengaturan | `GET` · `PUT /api/v1/pengaturan/tv` |
| Lihat kode TV | `GET /api/v1/pengaturan/tv/kode` |
| **Buat ulang kode** | `POST /api/v1/pengaturan/tv/kode/buat-ulang` |
| Daftar sesi TV aktif | `GET /api/v1/pengaturan/tv/sesi` |
| **Cabut** sesi | `DELETE /api/v1/pengaturan/tv/sesi/{id}` |
| Pratinjau | `GET /api/v1/pengaturan/tv/pratinjau` |

Yang dapat diatur: aktif/nonaktif, izinkan NPSN, interval refresh, tema, skala
font, tampilkan alasan izin, tampilkan ulang tahun, durasi rotasi panel, masa
berlaku token.

**Penting:**
- Kode TV adalah kode acak 8 karakter. Setelah kode dimasukkan, server
  menerbitkan **token TV read-only** (masa berlaku default **30 hari**) yang
  disimpan perangkat TV (FR-TV-03).
- **Token TV hanya berlaku untuk endpoint `tv`** — tidak dapat dipakai ke API
  lain (BR-32, K-76).
- **Membuat ulang kode mencabut SEMUA sesi TV** (BR-35, K-78). Gunakan bila
  perangkat TV hilang atau kode bocor; TV harus memasukkan kode baru.
- Sesi TV menampilkan nama perangkat, terakhir aktif, dan IP; tombol cabut
  mengakhiri satu sesi saja.
- Kode salah berulang **5×/menit/IP** dikunci sementara (BR-32, K-77).
- **Privasi (BR-33):** TV tidak menampilkan foto selfie, koordinat, NIP, nomor
  HP, alasan sakit (kecuali opsi aktif); siswa hanya sebagai agregat; avatar =
  huruf inisial.

---

## 10. Monitoring & persetujuan presensi

| Aksi | Endpoint |
|---|---|
| Monitoring harian (semua pegawai) | `GET /api/v1/monitoring/presensi-harian` |
| Detail satu presensi (foto, peta) | `GET /api/v1/monitoring/presensi-harian/{id}` |
| Antrean persetujuan presensi | `GET /api/v1/monitoring/persetujuan-presensi` |
| Setujui/tolak satu presensi | `PATCH /api/v1/monitoring/presensi-harian/{id}/putuskan` |
| Aksi massal | `POST /api/v1/monitoring/persetujuan-presensi/massal` |
| **Koreksi presensi** | `PATCH /api/v1/monitoring/presensi-harian/{id}/koreksi` |

- **Persetujuan presensi luar radius** (Jalur B) oleh admin/kepala sekolah.
  Status masuk (`hadir`/`terlambat`) dihitung dari **waktu presensi dikirim**,
  bukan waktu persetujuan (BR-18, K-43).
- **Koreksi manual** (mis. lupa presensi pulang, gangguan sistem) **wajib
  disertai alasan** dan dicatat di `audit_log`; data ditandai
  `dikoreksi_admin = true` (FR-PRS-13, K-67).

### Pengajuan izin/sakit/dinas/cuti

| Aksi | Endpoint |
|---|---|
| Buat atas nama pegawai | `POST /api/v1/pengajuan-izin/atas-nama/{pegawai}` (langsung `disetujui`, FR-IZN-08) |
| Setujui/tolak | `PATCH /api/v1/pengajuan-izin/{id}/putuskan` |

### Pengajuan luar radius

`PATCH /api/v1/pengajuan-luar-radius/{id}/putuskan`.

---

## 11. Laporan & ekspor (PDF / Excel)

Semua laporan mengembalikan JSON untuk web; tambahkan `?format=pdf` atau
`?format=excel` untuk mengunduh (KP-5.1). Dokumen PDF memakai **kop surat dan
blok tanda tangan** dari pengaturan (FR-KOP-06).

| Laporan | Endpoint |
|---|---|
| Rekap presensi pegawai | `GET /api/v1/laporan/presensi/rekap-pegawai` |
| Detail presensi pegawai | `GET /api/v1/laporan/presensi/detail-pegawai` |
| Presensi harian (semua pegawai) | `GET /api/v1/laporan/presensi/harian` |
| Rekap izin/sakit/dinas/cuti | `GET /api/v1/laporan/presensi/rekap-izin` |
| Rekap presensi luar radius | `GET /api/v1/laporan/presensi/rekap-luar-radius` |
| Rekap presensi siswa (dari jurnal) | `GET /api/v1/laporan/jurnal/rekap-siswa` |
| Daftar jurnal | `GET /api/v1/laporan/jurnal/daftar` |
| Rekap kepatuhan jurnal per guru | `GET /api/v1/laporan/jurnal/kepatuhan` |
| Rekap jam mengajar terlaksana | `GET /api/v1/laporan/jurnal/jam-mengajar` |
| Pengaturan dokumen pembuka laporan | `GET /api/v1/laporan/pengaturan-dokumen` |

Filter umum: periode (hari ini, minggu ini, bulan, rentang tanggal), tahun
pelajaran/semester (default aktif), pegawai, kelas, mapel (FR-LAP-01..09).

> **Catatan Excel:** ekspor `.xlsx` memerlukan header `Authorization`; unduhan
> di frontend memakai `fetch` + blob (K-24). Nama berkas dibaca dari header
> `Content-Disposition` yang telah dibuka eksplisit oleh CORS (K-85).

---

## 12. Audit log

- `GET /api/v1/pengaturan/audit-log` — daftar jejak.
- `GET /api/v1/pengaturan/audit-log/aksi` — daftar jenis aksi.
- **Hanya baca** (FR-SEC-05).

Dicatat antara lain: login gagal, reset perangkat, koreksi presensi,
persetujuan/penolakan, proses naik kelas, perubahan jurnal, perubahan pengaturan,
serta pembersihan foto retensi (BR-30/BR-31).

---

## 13. Retensi foto (BR-30)

Admin dapat menjalankan pembersihan manual setelah konfirmasi (BR-31):

```
POST /api/v1/tahun-pelajaran/{id}/bersihkan-foto   (wajib `konfirmasi` benar)
```

- Hanya tahun pelajaran berstatus **`selesai`** yang boleh dibersihkan; tahun
  yang belum selesai ditolak **422** berkode `TAHUN_BELUM_SELESAI` (K-93).
- Perintah artisan: `php artisan presensi:bersihkan-foto` (dijadwalkan harian
  01:30, K-92). Mode kering: `--kering`.
- Konsekuensi: berkas foto dihapus permanen; data teks tetap. **Baca
  [`PANDUAN-BACKUP.md`](PANDUAN-BACKUP.md) sebelum menjalankan.**
