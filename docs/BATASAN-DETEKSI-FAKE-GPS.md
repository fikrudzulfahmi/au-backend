# BATASAN DETEKSI FAKE GPS — Catatan untuk Pengelola (FR-SEC-08)

Dokumen ini ditulis sebagai **batasan yang harus dipahami pengelola**, **bukan
sebagai klaim keamanan**. Spesifikasi (`FR-SEC-08`, Bagian 5.16) secara eksplisit
meminta batasan ini dituliskan di dokumentasi:

> `FR-SEC-08` Batasan teknis yang harus **dituliskan di dokumentasi**: pada
> aplikasi web, deteksi *fake GPS* tidak mungkin sempurna. Mitigasi: kombinasi
> akurasi GPS, foto kamera langsung, pengikatan perangkat, watermark, dan
> tinjauan admin atas data luar radius.

Kesimpulan singkat: **SIPANDU tidak dapat menjamin deteksi pemalsuan lokasi.**
Sistem menerapkan beberapa pengaman, tetapi tidak satupun dari pengaman itu
merupakan deteksi pemalsuan yang andal. Baca seluruh dokumen sebelum mengandalkan
presensi GPS sebagai bukti tunggal kehadiran.

---

## 1. Apa yang sistem LAKUKAN

### 1.1 Menerapkan radius lokasi

- Setiap lokasi presensi memiliki **latitude, longitude, dan radius (meter)**
  dan dapat diatur admin tanpa deploy ulang (`FR-LOK-01`, `FR-LOK-06`).
- Server menghitung **jarak Haversine** antara titik GPS yang dikirim dan lokasi
  pegawai (`FR-PRS-06`, BR-11).
- Bila jarak ≤ radius salah satu lokasi pegawai → presensi **valid**.
- Bila di luar semua radius → masuk **alur luar radius dua jalur** (BR-17):
  - **Jalur A** — pegawai mengajukan *Presensi Luar Radius* lebih dulu;
    setelah disetujui, presensi pada tanggal itu berstatus `disetujui`.
  - **Jalur B** — presensi tetap diterima tetapi berstatus `menunggu` dan
    menunggu keputusan admin; presensi `menunggu` belum dihitung hadir.

### 1.2 Mencatat akurasi GPS

- Klien mengirim nilai **akurasi (meter)** bersama koordinat; nilai ini
  tersimpan pada setiap presensi (`FR-PRS-02`).
- Bila akurasi lebih buruk dari ambang `gps_max_akurasi_m` (default **50 m**),
  presensi **ditolak** dan pegawai diminta mencoba lagi — **bukan** diperlakukan
  sebagai "di luar radius" (`FR-PRS-06`, K-39). Pembedaan ini disengaja: akurasi
  buruk adalah persoalan teknis; luar radius adalah persoalan izin yang harus
  ditinjau admin.

### 1.3 Menyimpan koordinat dan foto

- Data yang disimpan per presensi: **waktu server**, latitude, longitude,
  akurasi, lokasi terdekat, jarak, foto, status, status validasi, keterangan
  (`FR-PRS-02`).
- **Foto wajib** dan diharapkan diambil langsung dari kamera; di klien
  diperkecil, di server dikompres ulang dan diberi **watermark** berisi nama,
  tanggal-jam server, dan koordinat. Target ukuran ≤ 150 KB (`FR-PRS-08`,
  BR-29, K-37, K-38).
- Foto disimpan di **disk privat** dan disajikan lewat endpoint berpelindung
  otorisasi (pemilik atau peran pemantau), bukan URL publik (K-36).
- **Waktu presensi selalu memakai waktu server**, bukan jam perangkat
  (BR-13, BR-38), sehingga memundurkan jam perangkat tidak menolong.

### 1.4 Mengikat perangkat & mencatat jejak

- Satu akun pegawai hanya dapat dipakai dari **satu perangkat terdaftar**
  (BR-14, FR-SEC-02). Login dari perangkat lain ditolak hingga admin mereset.
- Tindakan penting dicatat di `audit_log`: login gagal, reset perangkat,
  **koreksi presensi**, persetujuan/penolakan, perubahan pengaturan
  (`FR-SEC-05`), termasuk koreksi presensi oleh admin yang wajib disertai alasan
  (`FR-PRS-13`).
- Presensi luar radius ditinjau **manusia** (admin/kepala sekolah) atas foto,
  peta, dan alasan (`FR-PRS-11`).

---

## 2. Apa yang sistem TIDAK dapat jamin

### 2.1 Tidak ada deteksi pemalsuan lokasi yang andal di web

Peramban hanya menyediakan `navigator.geolocation`, yang mengembalikan koordinat
**apa pun** yang diberikan sistem operasi. Peramban **tidak dapat** membedakan
koordinat asli dari koordinat palsu. Akibatnya:

- **Mock location pada Android** memungkinkan pengguna menyetel koordinat palsu
  (mis. tepat di titik sekolah) yang akan lolos verifikasi radius.
- **USB debugging / developer options** memberi jalur yang sama.
- Aplikasi web tidak memiliki akses ke API tingkat-sistem yang dapat
  memverifikasi keaslian sumber lokasi.

SIPANDU **tidak** mengklaim dapat mendeteksi hal ini. Tidak ada modul, endpoint,
atau perintah di repositori yang memeriksa "mock location" — batasan ini
dinyatakan apa adanya.

### 2.2 Foto tidak dapat dijamin benar-benar dari kamera

- Spesifikasi menghendaki foto **hanya dari kamera** (`FR-PRS-08`), dan klien
  memakai `getUserMedia` untuk pratinjau kamera.
- Namun di sisi web, **server hanya menerima byte gambar**; server tidak dapat
  membuktikan byte itu benar-benar diambil dari sensor kamera saat itu, bukan
  dari sumber lain. `capture` pada elemen input di sisi klien hanya himbauan
  peramban, bukan penegakan yang tahan manipulasi.
- Foto tetap berguna sebagai **bukti pendukung** (ada wajah, ada jejak
  tanggal-jam-koordinat via watermark), tetapi bukan bukti mutlak kehadiran di
  lokasi.

### 2.3 Token perangkat tidak menyelesaikan masalah lokasi

- Pengikatan perangkat (BR-14) mencegah **akun** dipakai dari dua perangkat,
  tetapi **tidak** mencegah pemilik perangkat sah memalsukan lokasinya sendiri.
- Token perangkat disimpan di penyimpanan lokal peramban; pada perangkat yang
  di-root/di-jailbreak, penyimpanan itu dapat disalin.

---

## 3. Saran mitigasi manual

Karena deteksi teknis tidak dapat diandalkan, pengelolaan bergantung pada
**kombinasi prosedur**. Saran berikut selaras dengan mitigasi yang disebut
`FR-SEC-08` dan pengaturan yang benar-benar ada di sistem:

1. **Periksa foto, bukan hanya status.** Halaman **Monitoring Harian** dan
   **antrean persetujuan** menampilkan foto, peta, dan jarak (`FR-PRS-10`,
   `FR-PRS-11`). Foto dengan wajah tidak jelas, latar mencurigakan, atau
   watermark tidak wajar patut dipertanyakan.
2. **Tinjau anomali jarak & akurasi.** Presensi dengan akurasi sangat bagus
   (mis. 1–3 m) berulang di tempat yang diduga bukan — atau sebaliknya akurasi
   sangat buruk — adalah sinyal untuk diperiksa, bukan diterima otomatis.
3. **Gunakan audit log.** Periksa pola koreksi admin dan presensi luar radius
   yang sering berulang pada pegawai tertentu (`FR-SEC-05`).
4. **Kebijakan perangkat.** Terapkan larangan menyalakan mock location / USB
   debugging pada perangkat yang dipakai presensi, dan lakukan pemeriksaan
   berkala. Ini kebijakan organisasi, bukan fitur sistem.
5. **Verifikasi silang sesekali.** Bandingkan presensi dengan jadwal mengajar,
   jurnal yang diisi, dan pengajuan izin/dinas (`FR-IZN`, `FR-JRN`) — ketidakcocokan
   pola adalah petunjuk yang lebih kuat daripada koordinat tunggal.
6. **Edukasi & konsekuensi.** Beri tahu pegawai bahwa pemalsuan data presensi
   adalah pelanggaran tata tertib, dan tindaklanjuti temuan secara konsisten.
7. **Batasi kepercayaan pada luar radius otomatis.** Jalur A (pengajuan lebih
   dulu) membuat persetujuan berbasis dokumen (mis. surat tugas) — pertahankan
   keharusan lampiran untuk jenis pengajuan yang berkaitan.

---

## 4. Ringkasan untuk pengelola

| Pertanyaan | Jawaban jujur |
|---|---|
| Bisakah SIPANDU mendeteksi fake GPS? | **Tidak dapat dijamin.** |
| Apakah radius ditegakkan? | Ya, rumus Haversine terhadap lokasi pegawai. |
| Apakah akurasi GPS diperiksa? | Ya; akurasi buruk > ambang menolak presensi. |
| Apakah koordinat & foto disimpan? | Ya; koordinat, akurasi, foto ber-watermark, waktu server. |
| Apakah jam perangkat bisa dipakai curang? | Tidak — waktu server otoritatif (BR-13/BR-38). |
| Apakah mock location terdeteksi? | **Tidak ada deteksi otomatis di repositori.** |
| Apa yang paling andal? | **Tinjauan manusia** atas foto, jarak, dan pola, ditopang `audit_log`. |

Sumber: `FR-SEC-08`, `FR-PRS-02/06/08/10/11/13`, `FR-LOK-01/05/06`, BR-11,
BR-13, BR-14, BR-17, BR-29, BR-38, `README.md` §7, K-36, K-39.
