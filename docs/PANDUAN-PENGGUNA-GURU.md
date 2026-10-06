# PANDUAN PENGGUNA — GURU

Panduan ini untuk pengguna dengan peran **`guru`**. Guru melakukan presensi
masuk/pulang (kamera + GPS), mengajukan izin/sakit/dinas/cuti, mengisi jurnal
pembelajaran beserta presensi siswa, dan — bila ditetapkan sebagai **wali
kelas** — melihat rekap presensi kelasnya.

Sumber: spesifikasi Bagian 2 (matriks akses), 5.11–5.13, `routes/api.php`.

> **Wali kelas bukan peran terpisah.** Guru menjadi wali kelas bila tercatat
> sebagai `kelas.wali_kelas_id` pada tahun pelajaran aktif (spesifikasi Bagian 2).

---

## 1. Masuk aplikasi

1. Buka aplikasi dan login dengan **username** (NIP) dan password
   (`POST /api/v1/auth/login`).
2. Bila ini login pertama dari perangkat ini, aplikasi mendaftarkan
   **token perangkat**. Satu akun hanya boleh dipakai dari **satu perangkat
   terdaftar** (BR-14). Login dari perangkat lain ditolak: *"Akun ini terdaftar
   pada perangkat lain. Hubungi admin untuk reset perangkat."*
3. Ganti password awal saat diminta (FR-SEC-04) lewat
   `POST /api/v1/auth/ganti-password`.

> Aplikasi meminta izin **lokasi** dan **kamera** saat login pertama; keduanya
> wajib untuk presensi. Kamera & GPS hanya berjalan di koneksi **HTTPS**.

---

## 2. Presensi masuk & pulang (kamera + GPS)

### 2.1 Alur presensi masuk

1. Di beranda, lihat status hari ini: `GET /api/v1/presensi/hari-ini`
   (menampilkan status dan daftar lokasi efektif, BR-12).
2. Ketuk **Presensi Masuk**. Aplikasi menampilkan pratinjau kamera depan,
   **indikator akurasi GPS**, dan **jarak ke lokasi terdekat**.
3. Ambil **foto selfie langsung dari kamera** (bukan dari galeri). Foto
   dikompres di perangkat sebelum dikirim.
4. Kirim: `POST /api/v1/presensi/masuk`.

Presensi masuk **satu kali per hari** (BR-10). Waktu yang dipakai selalu
**waktu server**, bukan jam perangkat (BR-13) — memundurkan jam perangkat tidak
berpengaruh.

### 2.2 Alur presensi pulang

Setelah presensi masuk, tombol **Presensi Pulang** muncul. Kirim:
`POST /api/v1/presensi/pulang` (satu kali per hari, BR-10).

### 2.3 Aturan yang diberlakukan server

| Aturan | Perilaku |
|---|---|
| **Akurasi GPS buruk** | Bila akurasi > `gps_max_akurasi_m` (default 50 m), presensi **ditolak** — coba lagi di tempat lebih terbuka. Ini **bukan** "luar radius" (FR-PRS-06, K-39). |
| **Di dalam radius** | Bila jarak ≤ radius salah satu lokasi Anda → status **valid**. |
| **Di luar radius** | Lihat §2.4 di bawah (BR-17). |
| **Hari bukan hari kerja / libur** | Presensi ditolak dengan pesan jelas (K-40). |
| **Terlambat** | Status `terlambat` bila waktu server > `jam_masuk`, **tanpa toleransi** (BR-15); menit terlambat dihitung. |
| **Pulang cepat** | Status `pulang_cepat` bila waktu server < `jam_pulang` (BR-16). |
| **Foto** | **Wajib**, dari kamera, dikompres ulang di server + **watermark** (nama, tanggal-jam server, koordinat), target ≤ 150 KB (FR-PRS-08, BR-29). |

### 2.4 Presensi di luar radius — dua jalur (BR-17)

- **Jalur A — ajukan lebih dulu:** ajukan *Presensi Luar Radius*
  (`POST /api/v1/pengajuan-luar-radius`) untuk tanggal/rentang + alasan +
  lampiran opsional (mis. surat tugas). Setelah disetujui admin, presensi Anda
  pada tanggal itu langsung berstatus `disetujui`.
- **Jalur B — langsung presensi:** tanpa pengajuan, presensi tetap diterima
  tetapi berstatus **`menunggu`** dan Anda diminta mengisi alasan. Admin/kepala
  sekolah menyetujui (`disetujui`) atau menolak (`ditolak`).
- Presensi `menunggu` **belum dihitung hadir** sampai disetujui. Status
  `hadir`/`terlambat` tetap dihitung dari waktu presensi dikirim (BR-18).
- Bila **ditolak**, Anda boleh presensi ulang pada hari yang sama; rekaman lama
  disimpan di `audit_log` (FR-PRS-07, K-44).

### 2.5 Riwayat presensi

`GET /api/v1/presensi/riwayat` — riwayat presensi milik Anda (FR-PRS-12).

---

## 3. Pengajuan izin / sakit / dinas / cuti

### 3.1 Membuat pengajuan

`POST /api/v1/pengajuan-izin` dengan jenis (`izin` | `sakit` | `dinas` | `cuti`),
tanggal mulai–selesai, alasan, dan lampiran opsional (mis. surat dokter/surat
tugas) (FR-IZN-01).

- Pengajuan tidak boleh **bertumpuk tanggal** dengan pengajuan lain yang masih
  `menunggu`/`disetujui` milik Anda (FR-IZN-05).
- Untuk jenis **dinas**, tersedia kotak centang **"Presensi dari luar radius"**.
  Bila disetujui dan kotak dicentang, sistem otomatis membuat pengajuan luar
  radius untuk tiap hari kerja pada rentang itu (FR-IZN-02, K-41/K-42).

### 3.2 Status & pembatalan

Status: `menunggu`, `disetujui`, `ditolak`, `dibatalkan`. Anda hanya dapat
membatalkan selama masih `menunggu` (FR-IZN-03):
`PATCH /api/v1/pengajuan-izin/{id}/batalkan`. Penyetuju: admin atau kepala
sekolah; catatan penyetuju opsional (wajib saat menolak).

### 3.3 Akibat pengajuan yang disetujui

- Pada hari **izin/sakit/cuti** yang disetujui, tombol presensi tidak ditampilkan
  dan hari itu **tidak dihitung alpa** (FR-PRS-09, BR-25).
- Pada hari **dinas** yang disetujui, presensi tetap dilakukan (FR-PRS-09).
- Sesi mengajar pada hari izin/sakit/cuti/dinas yang disetujui tampil sebagai
  **"Berhalangan"** dan **tidak dihitung** sebagai jurnal belum diisi
  (FR-IZN-07, BR-26).

Riwayat pengajuan Anda: `GET /api/v1/pengajuan-izin` (FR-IZN-09).

---

## 4. Jurnal pembelajaran + presensi siswa

### 4.1 Melihat jadwal hari ini

`GET /api/v1/jadwal/hari-ini` menampilkan jadwal mengajar sendiri beserta status
jurnal tiap sesi (**Belum / Sudah / Berhalangan**) (FR-JDW-06). Daftar sesi
jurnal hari ini: `GET /api/v1/jurnal/sesi-hari-ini`.

### 4.2 Syarat mengisi jurnal (BR-19)

Jurnal untuk tanggal T hanya dapat **dibuat** bila Anda memiliki **presensi
masuk** pada tanggal T dengan validasi `valid`, `disetujui`, atau `menunggu`.
Jika belum presensi (atau presensi `ditolak`), server menolak dan UI menampilkan
*"Lakukan presensi masuk terlebih dahulu"* (FR-JRN-04).

### 4.3 Mengisi jurnal

Guru memilih sesi → **Isi Jurnal** → mengisi jurnal dan presensi siswa dalam
**satu halaman** → simpan: `POST /api/v1/jurnal`.

- **Sesi terbentuk dari jadwal:** entri jadwal berurutan untuk plotting mapel
  dan hari yang sama digabung menjadi **satu sesi** (mis. jam ke-1 s.d. 3 jadi
  satu jurnal). Jam selesai diambil dari jadwal, bukan dari kiriman Anda
  (FR-JRN-01, K-59, K-60).
- **Bidang jurnal** (FR-JRN-02): materi/topik **(wajib)**, kegiatan pembelajaran
  **(wajib)**, tanggal, kelas, mapel, jam ke (otomatis), catatan/kendala
  (opsional), **foto kegiatan** (opsional, maksimal **3** foto, dikompres).

### 4.4 Presensi siswa (satu halaman dengan jurnal)

- Daftar siswa berisi siswa **aktif** yang terplot di kelas tersebut pada tahun
  pelajaran jurnal (FR-JRN-03, FR-JRN-08, BR-22).
- Default **Hadir**; ubah yang tidak hadir menjadi **Sakit (S)**, **Izin (I)**,
  atau **Alpa (A)** dengan keterangan opsional. Ada tombol **"Semua Hadir"**.
- Ringkasan jumlah **H/S/I/A** tampil real-time.
- Siswa di luar kelas **ditolak**, bukan diabaikan diam-diam (K-62) — agar
  ketidakcocokan terlihat saat itu juga.

### 4.5 Syarat & batas lain

- **Satu sesi hanya satu jurnal** (unik per plotting mapel + tanggal + jam
  mulai, BR-21). Duplikat ditolak dengan pesan "muat ulang halaman" (K-61).
- Anda hanya dapat mengisi jurnal untuk sesi **pada jadwal Anda**. Jurnal untuk
  tanggal lampau diizinkan asalkan presensi masuk pada tanggal itu memenuhi
  BR-19 (FR-JRN-06).
- **Edit tanpa batas waktu** oleh pemilik jurnal (BR-20); setiap perubahan
  dicatat di `audit_log` (FR-JRN-05). Mengubah jurnal mengganti presensi siswa
  secara **utuh** (K-64).

### 4.6 Riwayat jurnal

`GET /api/v1/jurnal` — riwayat jurnal milik sendiri, dengan filter
periode/kelas/mapel (FR-JRN-09). Detail: `GET /api/v1/jurnal/{id}`.

---

## 5. Rekap presensi siswa kelas wali (wali kelas)

Bila Anda ditetapkan sebagai wali kelas pada tahun pelajaran aktif:

- Lihat kelas wali milik Anda: `GET /api/v1/jurnal/kelas-wali`
  (endpoint khusus; guru **tidak** memakai master `/kelas` yang hanya untuk
  pemantau — K-68).
- Rekap presensi siswa kelas wali: `GET /api/v1/jurnal/rekap-siswa` atau
  `GET /api/v1/laporan/kelas-wali/rekap-siswa` — per siswa, per periode
  (H/S/I/A dan persentase) (FR-JRN-10, KP-5.5).

Akses dibatasi **di server**: kelas lain → **403**; guru bukan wali → **403**
(K-73).

---

## 6. Laporan untuk guru

Guru boleh **membaca laporan untuk dirinya sendiri (L/S)**:

| Laporan | Endpoint |
|---|---|
| Rekap presensi Anda | `GET /api/v1/laporan/presensi/rekap-pegawai` |
| Detail presensi Anda | `GET /api/v1/laporan/presensi/detail-pegawai` |
| Rekap izin Anda | `GET /api/v1/laporan/presensi/rekap-izin` |
| Rekap luar radius | `GET /api/v1/laporan/presensi/rekap-luar-radius` |
| Laporan jurnal & presensi siswa | `GET /api/v1/laporan/jurnal/rekap-siswa`, `/daftar`, `/kepatuhan`, `/jam-mengajar` |

Tambahkan `?format=pdf` atau `?format=excel` untuk mengunduh. Server otomatis
membatasi data ke milik Anda (FR-LAP-12).

> Kepala sekolah & wakasek punya hak **lihat laporan jurnal**, tetapi **tidak**
> pada endpoint jurnal pengisian. Ini pembatasan yang disengaja (K-66, K-74).

---

## 7. Catatan penting

- **Presensi tidak dapat menggantikan dokumentasi lokal.** Foto dan koordinat
  berguna sebagai bukti pendukung, tetapi deteksi lokasi palsu tidak dapat
  dijamin — lihat [`BATASAN-DETEKSI-FAKE-GPS.md`](BATASAN-DETEKSI-FAKE-GPS.md).
- **Jangan ubah jam perangkat** untuk "memperbaiki" waktu presensi — server
  selalu memakai waktunya sendiri (BR-13).
- Koreksi presensi/jurnal hanya dapat dilakukan admin/kepala sekolah dengan
  alasan dan tercatat di `audit_log`.
