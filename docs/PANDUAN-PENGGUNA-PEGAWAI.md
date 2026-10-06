# PANDUAN PENGGUNA — PEGAWAI STRUKTURAL

Panduan ini untuk pengguna dengan peran **`pegawai_struktural`** — pegawai yang
**bukan guru** (mis. tenaga administrasi, staf, pustakawan, laboran). Peran ini
otomatis diperoleh bila `pegawai.jenis_pegawai = 'struktural'`.

Tugas utama peran ini: **presensi masuk/pulang**, **pengajuan
izin/sakit/dinas/cuti**, dan melihat **riwayat**. Peran ini **tidak** mengisi
jurnal dan **tidak** menangani presensi siswa.

Sumber: spesifikasi Bagian 2 (matriks akses), 5.11–5.12, `routes/api.php`.

---

## 1. Ringkasan hak akses

| Fitur | pegawai_struktural |
|---|---|
| Presensi masuk/pulang | **K** (data sendiri) |
| Pengajuan izin / dinas / luar radius | **K** (data sendiri) |
| Jurnal + presensi siswa | **-** (tidak ada) |
| Laporan presensi pegawai | **L(S)** — hanya dirinya |
| Laporan jurnal & presensi siswa | **-** (tidak ada) |
| Master data, plotting, jadwal mengajar | **-** (tidak ada) |

Sumber: matriks akses spesifikasi Bagian 2.

---

## 2. Masuk aplikasi

1. Login dengan **username** (NIP) dan password
   (`POST /api/v1/auth/login`).
2. Pada login pertama dari perangkat ini, aplikasi mendaftarkan **token
   perangkat**. Satu akun hanya boleh dipakai dari **satu perangkat terdaftar**
   (BR-14). Login dari perangkat lain ditolak; hubungi admin untuk **reset
   perangkat** (`POST /api/v1/pegawai/{id}/reset-perangkat`).
3. Ganti password awal saat diminta (FR-SEC-04):
   `POST /api/v1/auth/ganti-password`.

> Aplikasi meminta izin **lokasi** dan **kamera**; keduanya wajib untuk
> presensi, dan hanya berjalan di koneksi **HTTPS**.

---

## 3. Presensi harian (kamera + GPS)

Alurnya sama dengan panduan guru; bedanya tidak ada jurnal.

### 3.1 Presensi masuk

1. Lihat status hari ini dan daftar lokasi efektif Anda:
   `GET /api/v1/presensi/hari-ini`.
2. Ketuk **Presensi Masuk** → ambil **foto selfie langsung dari kamera**
   (bukan galeri), sambil memperhatikan indikator akurasi GPS dan jarak ke
   lokasi terdekat.
3. Kirim: `POST /api/v1/presensi/masuk`.

Presensi masuk **satu kali per hari** (BR-10). Waktu memakai **waktu server**
(BR-13).

### 3.2 Presensi pulang

Setelah masuk, tombol **Presensi Pulang** muncul → kirim:
`POST /api/v1/presensi/pulang` (satu kali per hari, BR-10).

### 3.3 Aturan yang diberlakukan server

| Aturan | Perilaku |
|---|---|
| **Akurasi GPS buruk** | Bila akurasi > `gps_max_akurasi_m` (default 50 m), presensi ditolak — coba lagi; ini bukan "luar radius" (FR-PRS-06, K-39). |
| **Dalam radius** | Jarak ≤ radius salah satu lokasi Anda → **valid**. |
| **Luar radius** | Dua jalur (BR-17) — lihat §3.4. |
| **Bukan hari kerja / libur** | Presensi ditolak dengan pesan jelas (K-40). |
| **Terlambat** | `terlambat` bila waktu server > `jam_masuk`, tanpa toleransi (BR-15). |
| **Pulang cepat** | `pulang_cepat` bila waktu server < `jam_pulang` (BR-16). |
| **Foto** | Wajib, dari kamera, dikompres ulang di server + watermark, target ≤ 150 KB (FR-PRS-08, BR-29). |

### 3.4 Presensi di luar radius — dua jalur (BR-17)

- **Jalur A — ajukan lebih dulu:** `POST /api/v1/pengajuan-luar-radius`
  (tanggal/rentang + alasan + lampiran opsional). Setelah disetujui, presensi
  Anda pada tanggal itu berstatus `disetujui`.
- **Jalur B — langsung presensi:** presensi tetap diterima tetapi berstatus
  `menunggu` dan Anda diminta mengisi alasan. Admin/kepala sekolah memutuskan.
  Presensi `menunggu` **belum dihitung hadir** sampai disetujui; status
  `hadir`/`terlambat` tetap dari waktu presensi dikirim (BR-18).
- Bila **ditolak**, Anda boleh presensi ulang pada hari yang sama; rekaman lama
  di `audit_log` (FR-PRS-07).

### 3.5 Riwayat presensi

`GET /api/v1/presensi/riwayat` — riwayat presensi milik sendiri (FR-PRS-12).

---

## 4. Pengajuan izin / sakit / dinas / cuti

1. Buat pengajuan: `POST /api/v1/pengajuan-izin` dengan jenis
   (`izin` | `sakit` | `dinas` | `cuti`), tanggal mulai–selesai, alasan, dan
   lampiran opsional (mis. surat dokter/surat tugas) (FR-IZN-01).
2. Pengajuan tidak boleh bertumpuk tanggal dengan pengajuan lain yang masih
   `menunggu`/`disetujui` (FR-IZN-05).
3. Untuk jenis **dinas**, tersedia kotak centang **"Presensi dari luar radius"**
   (FR-IZN-02) — bila disetujui, sistem membuat pengajuan luar radius untuk tiap
   hari kerja pada rentang itu.
4. **Batalkan** hanya selama status masih `menunggu`:
   `PATCH /api/v1/pengajuan-izin/{id}/batalkan` (FR-IZN-03).
5. Lihat riwayat & status: `GET /api/v1/pengajuan-izin` (FR-IZN-09).

**Akibat pengajuan disetujui:**
- Hari **izin/sakit/cuti** disetujui → tombol presensi tidak ditampilkan dan
  hari itu **tidak dihitung alpa** (FR-PRS-09, BR-25).
- Hari **dinas** disetujui → presensi **tetap** dilakukan (FR-PRS-09).

Penyetuju: **admin** atau **kepala sekolah**; catatan wajib saat menolak
(FR-IZN-04).

---

## 5. Riwayat & laporan

- Riwayat presensi: `GET /api/v1/presensi/riwayat`.
- Riwayat pengajuan: `GET /api/v1/pengajuan-izin`.
- Rekap presensi **milik sendiri**: `GET /api/v1/laporan/presensi/rekap-pegawai`
  (dan `.../detail-pegawai`, `.../rekap-izin`, `.../rekap-luar-radius`) — server
  membatasi ke data Anda (FR-LAP-12).
- Tambahkan `?format=pdf` atau `?format=excel` untuk mengunduh.

> Peran `pegawai_struktural` **tidak** berhak atas laporan jurnal & presensi
> siswa (matriks Bagian 2; rute `/laporan/jurnal/*` menolak peran ini).

---

## 6. Catatan penting

- **Jangan mengubah jam perangkat** — waktu presensi selalu dari server
  (BR-13/BR-38).
- **Foto & lokasi adalah data pribadi.** Hanya Anda dan peran pemantau berwenang
  (admin/kepala sekolah/wakasek) yang dapat melihatnya; foto disajikan lewat
  endpoint berpelindung, bukan tautan publik (K-36).
- **Deteksi lokasi palsu tidak dapat dijamin.** Foto, akurasi, radius, dan
  pengikatan perangkat membantu, tetapi tidak membuktikan keaslian lokasi —
  lihat [`BATASAN-DETEKSI-FAKE-GPS.md`](BATASAN-DETEKSI-FAKE-GPS.md).
