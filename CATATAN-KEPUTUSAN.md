# Catatan Keputusan — SIPANDU

Dokumen ini mencatat asumsi yang dipakai dan penyimpangan dari
`spesifikasi-aplikasi-presensi-smk.md`, sesuai Petunjuk Untuk Agent butir 5.
Asumsi default yang sudah tertulis di Bagian 13 dokumen tidak diulang di sini.

Tanggal: 6 Oktober 2026 · Fase selesai: 0 (kerangka dua repo & UI), 1 (master data & Info Sekolah), 2 (plotting & jadwal), 3 (presensi & pengajuan)

---

## A. Lingkungan

| No | Keputusan | Alasan / dampak |
|---|---|---|
| K-01 | PHP 8.3 dipasang **terpisah** di `C:\php83` sebagai runtime CLI proyek. XAMPP tetap memakai PHP 8.2. | Spesifikasi meminta PHP 8.3, tetapi XAMPP pada mesin ini berisi PHP 8.2 dan dipakai bersama proyek lain. Menimpa PHP XAMPP berisiko merusak proyek tersebut. Skrip penyiapan: `tools/siapkan-php83.py`. |
| K-02 | Basis data uji memakai **MySQL/MariaDB** (`sipandu_test`), bukan SQLite in-memory. | Ekstensi `pdo_sqlite` tidak aktif pada PHP XAMPP asli dan sebagian tipe kolom berbeda; menguji pada mesin yang sama dengan produksi lebih meyakinkan. |
| K-03 | Pest dipakai pada versi 3.x (phpunit 11.5), sedangkan Pest 4.x menuntut phpunit 12. | Menjaga kesesuaian dengan skeleton Laravel 12 yang mengunci `phpunit/phpunit ^11.5`. |

## B. Model data

| No | Keputusan | Alasan / dampak |
|---|---|---|
| K-04 | Tabel **`pegawai`** dibuat pada Fase 0, bersama tabel kelompok 7.1. | `users.pegawai_id` (7.1) adalah foreign key ke `pegawai` sehingga tabel induknya harus ada lebih dulu. Fase 1 tetap menambahkan CRUD, import/export, dan penetapan lokasi pegawai. |
| K-05 | Kolom `name` dan `email` tetap ada pada `users` (bawaan Laravel) walau tidak disebut di 7.1. Login memakai `username`. | `name` dipakai sebagai nama tampilan cadangan untuk akun yang tidak terhubung pegawai (mis. admin); `email` **nullable** dan tidak dipakai untuk autentikasi karena tidak semua pegawai punya email sekolah. |
| K-06 | Kolom `profil_sekolah.npsn` dan `nama_kepala_sekolah` dibuat **nullable** walau Bagian 7.6 menandainya wajib. | Bagian 10 secara eksplisit meminta kedua nilai ini **dikosongkan** pada seeder awal ("jangan diisi data karangan"). Kewajiban diisi ditegakkan pada validasi formulir admin (Fase 1, FR-SCH-04), bukan pada skema. |
| K-07 | Seeder membuat akun login untuk **seluruh 10 pegawai contoh**, bukan hanya admin/kepsek/wakasek. | FR-PEG-02 menyatakan setiap pegawai dapat dibuatkan akun; tanpa akun guru, beranda guru dan alur jurnal tidak dapat diuji. Seluruh akun memakai password awal yang wajib diganti. |
| K-08 | Baris `penandatangan` contoh memakai `nama` berisi string kosong. | Bagian 10 meminta satu penandatangan "Kepala Sekolah" dengan nama kosong sampai diisi admin, sedangkan kolom `nama` bersifat wajib (7.1). |

## C. Perilaku aplikasi

| No | Keputusan | Alasan / dampak |
|---|---|---|
| K-09 | `wajib_ganti_password` ditegakkan sebagai **gerbang lunak di UI**: pengguna melihat peringatan menonjol dan ditawarkan mengganti password, tetapi tidak dikunci dari seluruh aplikasi. | FR-SEC-04 menyatakan password awal wajib diganti, namun tidak menetapkan hukuman bila belum dilakukan. Gerbang lunak menjaga kepatuhan tanpa menghalangi peninjauan aplikasi. Ubah menjadi gerbang keras pada `RequireAuth` bila diinginkan. |
| K-10 | Data pada beranda Fase 0 (`usePresensiHariIni`, `useRingkasanHariIni`, daftar pengumuman) adalah **data contoh** di sisi frontend. | KP-0.5 memang meminta kartu *Presensi & Kinerja* dan grid layanan tampil dengan data dummy. Hook-nya sudah menyiapkan pemanggilan endpoint `GET /presensi/hari-ini` dan `GET /monitoring/presensi-harian/ringkasan` yang akan diisi pada Fase 3. |
| K-11 | Pengumuman, layar TV, pengaturan, master data, dan seluruh laporan pada Fase 0 berupa **halaman penanda fase**. | Petunjuk butir 2 dan 4: jangan membangun fitur dari fase yang belum diminta; halaman disiapkan agar navigasi lengkap sesuai Bagian 8.1. |
| K-12 | Landing page memuat bagian pengumuman hanya bila daftarnya tidak kosong (FR-LND-05). | Pengumuman baru ada pada Fase 6 (`pengumuman`), sehingga pada Fase 0 bagian ini tidak dirender — sesuai FR-LND-05 yang meminta bagian disembunyikan bila kosong. |
| K-13 | `FRONTEND_URL` menerima beberapa origin dipisah koma (bawaan memuat `localhost:5173` dan `127.0.0.1:5173`). | CORS hanya mengizinkan origin yang terdaftar (3.3); kedua bentuk alamat itu dipakai bergantian pada pengembangan dan keduanya adalah origin yang berbeda bagi peramban. |

## D. Aset & desain

| No | Keputusan | Alasan / dampak |
|---|---|---|
| K-14 | Ilustrasi guru khaki memakai **placeholder SVG** di `src/assets/ilustrasi/`. | A-18 dan FR-UI-18 memperbolehkan placeholder sampai aset resmi dibuat pemilik proyek. Berkas mudah diganti tanpa mengubah komponen. |
| K-15 | Tangkapan layar referensi SIKEPO belum tersedia, sehingga `docs/referensi/` baru berisi keterangan tempat. | FR-UI meminta salinan tangkapan layar disimpan di `docs/referensi/`; token warna pada `src/styles/tokens.css` mengikuti tabel 5.22 dan boleh disetel (A-22). |
| K-16 | Ikon PWA dibuat terprogram lewat `tools/buat-ikon.php` (GD) alih-alih berkas desain. | Menjaga repo tanpa berkas biner tambahan dan memudahkan pembuatan ulang pada ukuran lain. |

## E. Yang sengaja belum dikerjakan

Sesuai Bagian 12 (di luar cakupan) dan Bagian 11 (kerja per fase), hal berikut
belum ada dan tidak akan ditambahkan tanpa permintaan: notifikasi WhatsApp/SMS/email,
integrasi Dapodik, penilaian/keuangan, akun siswa, aplikasi native,
SSR untuk landing, layar TV interaktif, mode gelap aplikasi, multi-sekolah,
pengenalan wajah, dan guru pengganti/team teaching.

---

## F. Catatan Fase 1 (master data & Info Sekolah)

Tanggal: 6 Oktober 2026 · Status: selesai (94 uji backend, 24 uji frontend)

| No | Keputusan | Alasan / dampak |
|---|---|---|
| K-17 | Penjaga penghapusan (`App\Support\PenjagaHapus`) memeriksa **keberadaan tabel** rujukan sebelum menolak. | FR-SIS-06, FR-KLS-05, dan FR-MPL-03 mengacu ke tabel yang baru ada pada fase berikutnya (`plotting_mapel` Fase 2, `presensi_siswa`/`jurnal` Fase 4). Dengan pola ini penjaga **otomatis aktif** begitu tabelnya dibuat, tanpa mengubah controller. Selama tabelnya belum ada, penghapusan tetap berupa soft delete sehingga riwayat tidak benar-benar hilang. Mekanismenya diuji memakai tabel yang sudah ada. |
| K-18 | Validasi format berkas import memakai **ekstensi nama berkas + benar-benar dapat dibaca PhpSpreadsheet**, bukan aturan `mimes:`. | Berkas xlsx adalah arsip ZIP, sehingga deteksi tipe oleh server dapat melaporkannya sebagai `application/zip` dan menolak berkas yang sebenarnya sah. Memuat berkas dengan pembaca Excel adalah pemeriksaan yang paling kuat. |
| K-19 | FR-SCH-05 (tab Landing Page) mendapat endpoint sendiri: `GET/PUT /pengaturan/landing`. | Tab Landing berada pada halaman yang sama dengan Info Sekolah (5.18), sehingga berada dalam cakupan Fase 1. Nilainya tetap tersimpan di tabel `pengaturan` (7.6). |
| K-20 | Password awal akun pegawai dibuat **acak** (`Str::password(12)`) dan dilaporkan satu kali pada respons pembuatan/reset. | FR-PEG-02 meminta "password awal acak yang wajib diganti". Password awal tidak pernah disimpan dalam bentuk terbaca dan tidak pernah dikirim pada endpoint daftar. |
| K-21 | Export master data pada Fase 1 hanya **xlsx**. Permintaan `format=pdf` dijawab 422 dengan pesan jelas. | FR-SIS-04/FR-PEG-03 menyebut Excel; PDF master data memerlukan kop surat dan tanda tangan (FR-KOP-06) yang baru dibangun pada Fase 5. Dijawab 422, bukan 500, agar klien tahu alasannya. |
| K-22 | `DB_ENGINE=InnoDB` dipaksa pada konfigurasi `mysql` dan `mariadb`, ditambah `Schema::defaultStringLength(191)`. | Produksi berupa cPanel yang default engine/bagian row-formatnya tidak pasti; keduanya mencegah kegagalan `1071 Specified key was too long` dan memastikan foreign key benar-benar ditegakkan. |
| K-23 | Filter siswa per kelas/jurusan/tingkat belum aktif pada Fase 1. | Ketiganya bergantung pada `plotting_kelas` (Fase 2). Pola yang sama seperti K-17: filter memakai pemeriksaan keberadaan tabel sehingga langsung berfungsi pada Fase 2. Filter yang sudah aktif: pencarian (NIS/NISN/nama), status, dan tahun masuk. |
| K-24 | Tautan unduhan (export/template) memakai `fetch` + blob, bukan `<a href>` biasa. | Endpoint ekspor memerlukan header `Authorization: Bearer`, sehingga tautan biasa tidak dapat dipakai. |
| K-25 | Pembersihan buffer keluaran manual **tidak** dilakukan sebelum mengirim berkas XLSX. | Di dalam Laravel, `response()->download()` sudah menangani pengiriman berkas secara utuh. Pembersihan buffer manual justru menutup buffer milik kerangka uji dan membuat tes ditandai "risky" oleh PHPUnit. Untuk aplikasi PHP native (tanpa lapisan respons Laravel) pola itu tetap diperlukan. |

### Bug yang ditemukan dan diperbaiki pada Fase 1

| Bug | Akar masalah | Perbaikan |
|---|---|---|
| Tahun 4 digit (mis. `2024`) pada import tersimpan sebagai **1905-07-18** | Nilai `2024` berada di dalam rentang serial tanggal Excel (1..2958465), sehingga dibaca sebagai serial | `ExcelService::normalisasiTanggal()` tidak lagi menafsirkan bilangan bulat 1900–2100 sebagai serial; tahun dibaca lebih dahulu, dan angka 8 digit (mis. `20240517`) diperlakukan sebagai `Ymd` |
| Password awal hasil import pegawai selalu kosong | `PegawaiService::buat()` sudah membuat akun, lalu `buatAkun()` dipanggil lagi dan mengembalikan `password_awal = null` | Akun dibuat setelah pegawai tersimpan, sehingga password awalnya dapat dilaporkan |
| Nama tahun pelajaran pada factory dapat bertabrakan | `nama` dibuat acak dari rentang tahun yang sama | Factory memakai penghitung berurutan agar `nama` selalu unik |

---

## H. Catatan Fase 2 (plotting & jadwal)

Tanggal: 6 Oktober 2026 · Status: selesai (154 uji backend, 31 uji frontend)

| No | Keputusan | Alasan / dampak |
|---|---|---|
| K-26 | Aturan bentrok ditegakkan **berlapis**: validasi aplikasi + indeks unik database. | Aplikasi menghasilkan pesan yang menyebut pelaku bentroknya ("Bentrok guru: X sudah mengajar Y di Z"), sedangkan indeks unik menutup celah balapan dua permintaan bersamaan. `QueryException` duplikat tetap diterjemahkan menjadi pesan yang dapat dibaca, bukan 500. |
| K-27 | Pratinjau wizard menghitung **status akhir bawaan dan saran kelas tujuan di server**. | Aturan tingkat (X/XI → naik_kelas, XII → lulus) dan saran "+1 tingkat, jurusan sama" adalah aturan bisnis, jadi harus satu sumber. Frontend hanya menyajikan, tidak menghitung. |
| K-28 | Idempotensi wizard bertumpu pada `status_akhir` baris asal dan keberadaan baris plotting di tahun tujuan. | Setiap keputusan diperiksa lebih dulu; siswa yang sudah diproses dilewati dan dilaporkan, bukan digandakan atau digagalkan. Penanda "kelas selesai" dihitung dari ada/tidaknya siswa yang masih `berjalan`, sehingga tidak perlu tabel status tambahan. |
| K-29 | `pegawai_id` dan `kelas_id` pada `jadwal` didenormalisasi dari plotting (sesuai 7.3 catatan) dan **ikut diperbarui** ketika pengampu plotting diubah. | Tanpa itu, mengganti guru pengampu akan meninggalkan jadwal lama pada guru sebelumnya sehingga pengecekan bentrok (BR-06) menjadi salah. Ini diuji secara khusus. |
| K-30 | FR-PLK-06 (batalkan naik kelas) memakai `PenjagaHapus` seperti penjaga fase sebelumnya. | Aturan "selama tahun tujuan belum punya jurnal bagi siswa itu" mengacu ke tabel Fase 4. Dengan pola ini pembatalan otomatis tertutup begitu `presensi_siswa` dibuat, tanpa mengubah kode. |
| K-31 | Batas L/S guru ditegakkan **di server**, bukan hanya menyembunyikan menu. | Guru tidak dapat melihat jadwal/plotting guru lain, termasuk ketika mencoba mengirim `pegawai_id` milik orang lain — parameter itu diabaikan dan diganti dengan data pegawai miliknya sendiri. |
| K-32 | Seeder jadwal menyusun jadwal dengan offset per (hari, slot) sehingga tidak mungkin bentrok, lalu menyetel `jp_per_minggu` dari jumlah JP yang benar-benar terjadwal. | Karena setiap mapel diampu tepat satu guru, memutar indeks mapel per kelas menjamin BR-06 dan BR-07 aman sejak data awal. Menyetel JP dari hasil nyata membuat BR-09 dan FR-JDW-07 konsisten (tidak ada peringatan palsu). |
| K-33 | Rute `/plotting/kelas/mutasi` menampilkan halaman Plotting Kelas yang sama. | Mutasi adalah tindakan per siswa (tombol pada baris daftar), bukan layar tersendiri. Halaman terpisah hanya akan menduplikasi daftar yang sama. |

### Bug yang ditemukan dan diperbaiki pada Fase 2

Selain bug pada kode baru Fase 2, ada tiga cacat Fase 1 yang baru terlihat ketika dipakai:

| Bug | Akar masalah | Perbaikan |
|---|---|---|
| `meta` tambahan di respons daftar tidak pernah sampai ke klien | `ResponsDaftar::buat()` hanya menerima 2 argumen; PHP tidak mengeluh kelebihan argumen, jadi argumen ketiga dibuang diam-diam | Ditambahkan parameter `$metaTambahan` |
| `ImportMasterService` memakai tipe `?User` tanpa mengimpor `User` | Tipe parameter diselesaikan lambat; selama selalu `null` tidak pernah meledak | `use App\Models\User;` ditambahkan |
| Pelaku import pegawai tidak tercatat di `audit_log` | Controller tidak mengirim `$oleh`, sehingga nilainya `null` | Controller mengirim `$request->user()` |
| Filter plotting ambigu setelah join | `tahun_pelajaran_id` ada di `plotting_kelas` dan `kelas` → MySQL 1052 | Kolom dikualifikasi dengan nama tabel |
| `$semester->label` melempar galat resolusi relasi | `label` adalah **method**, bukan kolom | Memakai `$semester->label()` |
| Pembatalan naik kelas tidak pernah menghapus baris tujuan | Salah ketik `$asar` (variabel tak dikenal → `null`) | Memakai `$asal`; ditambah uji yang menutupnya |
| Seeder gagal: `values()` pada Builder | `values()` milik Collection | Ditambah `->get()` |
| Factory menghasilkan data tak konsisten | Plotting kelas membuat dua kelas berbeda; jadwal memakai id tetap `1` | Factory membangun rangkaian yang sah |

---

## I. Catatan Fase 3 (presensi & pengajuan)

Tanggal: 6 Oktober 2026 · Status: selesai (219 uji backend, 31 uji frontend)

| No | Keputusan | Alasan / dampak |
|---|---|---|
| K-34 | BR-12 "hanya satu lokasi default" ditegakkan **database**, bukan hanya layanan, lewat kolom bantu `penanda_default` (1 untuk default, NULL untuk sisanya) pada indeks unik. | MySQL mengizinkan banyak NULL pada indeks unik, sehingga pola ini memberi jaminan "maksimal satu" tanpa tabel tambahan. Diverifikasi langsung: default kedua ditolak (duplikat 1062) sementara lokasi non-default boleh banyak. |
| K-35 | Presensi **satu baris per pegawai per tanggal** yang memuat kolom masuk dan pulang berdampingan, bukan dua tabel terpisah. | BR-10 (satu masuk, satu pulang per hari) menjadi indeks unik `(pegawai_id, tanggal)`, dan keadaan "pulang tanpa masuk" secara struktural tidak mungkin tersimpan. Status hari itu juga dapat dibaca dengan satu query — penting untuk monitoring harian. |
| K-36 | Foto presensi disimpan di disk **privat** dan disajikan lewat endpoint berpelindung otorisasi (pemilik atau peran pemantau), bukan URL publik. | Foto wajah pegawai bersifat pribadi dan dipakai pada halaman monitoring. Otorisasi diperiksa per permintaan, dan foto hilang karena retensi dijawab 404 dengan penjelasan, bukan 500. |
| K-37 | Batas foto presensi memakai setelan `foto_target_maks_kb` (150 KB), **terpisah** dari `MAKS_HASIL_BYTE` milik logo sekolah (300 KB). | BR-29 menyebut 150 KB sedangkan FR-SCH-04 menyebut 300 KB untuk logo. Menyatukan keduanya akan melanggar salah satu; karena itu jalur foto presensi punya penurunan kualitas bertahap dan, bila perlu, penurunan dimensi. |
| K-38 | Watermark digambar memakai **font bawaan GD** (tanpa berkas TTF di repositori). | Terverifikasi berjalan dan mengubah piksel. Menghindari menambahkan biner font ke repo dan tetap bekerja di Linux produksi. Trade-off: ukuran huruf terbatas; bila kelak perlu lebih besar, tambahkan TTF ke `resources/fonts` dan setel `filename()` pada FontFactory. |
| K-39 | Akurasi GPS yang lebih buruk dari `gps_max_akurasi_m` **menolak** presensi, tidak diperlakukan sebagai "di luar radius". | Sesuai FR-PRS-06. Perbedaannya penting: luar radius adalah persoalan izin (dapat ditinjau admin), sedangkan akurasi buruk adalah persoalan teknis yang harus dicoba lagi — mencampurnya akan membuat antrean persetujuan penuh oleh presensi yang sebenarnya bisa dikirim ulang. |
| K-40 | Presensi pada hari **bukan hari kerja** atau hari libur ditolak dengan pesan jelas. | Jam kerja (FR-LOK-04) hanya bermakna bila hari kerja benar-benar ditegakkan; tanpa ini, perhitungan alpa dan kepatuhan menjadi tidak konsisten. Admin tetap dapat mengoreksi bila ada keadaan khusus (FR-PRS-13). |
| K-41 | Pengajuan luar radius disimpan **satu baris per tanggal**, bukan rentang. | Membuat BR-17 Jalur A deterministik: pencocokan pengajuan dengan presensi cukup satu pencarian `(pegawai_id, tanggal)` dan dijaga indeks unik. Rentang dari dinas (FR-IZN-02) dipecah per hari kerja saat persetujuan. |
| K-42 | Penurunan pengajuan luar radius dari dinas yang disetujui dilakukan **saat persetujuan**, dan `updateOrCreate` agar aman diulang. | Persetujuan dapat dilakukan ulang atau diperbaiki; `updateOrCreate` mencegah galat duplikat. Menolak dinas membatalkan turunan yang sudah terlanjur disetujui. |
| K-43 | Status hadir/terlambat dihitung **saat presensi dikirim** dan disimpan, bukan dihitung ulang saat keputusan. | BR-18. Diuji khusus: presensi terlambat 30 menit tetap terlambat 30 menit setelah admin menyetujui. |
| K-44 | Presensi yang **ditolak** boleh dikirim ulang pada hari yang sama; rekaman lama tercatat di `audit_log`. | FR-PRS-07 menyebut perilaku ini eksplisit. Baris tetap satu (BR-10), hanya isinya diganti, dan foto lama dihapus agar tidak menumpuk. |
| K-45 | Kategori monitoring dihitung **saat diminta**, tidak disimpan. | Status dapat berubah tanpa aksi apa pun (mis. tenggat pulang terlewat), sehingga nilai tersimpan akan cepat basi. Perhitungan ulang juga menjadi sumber tunggal kebenaran dengan laporan pada Fase 5 (BR-37). |
| K-46 | Batas L/S untuk foto presensi dan monitoring ditegakkan di **server**, bukan hanya menyembunyikan menu. | Guru hanya melihat jadwal dan plotting miliknya (Fase 2) dan hanya foto presensinya sendiri di sini; peran pemantau (admin/kepsek/wakasek) yang boleh melihat foto pegawai lain. |

### Bug yang ditemukan dan diperbaiki pada Fase 3

| Bug | Akar masalah | Perbaikan |
|---|---|---|
| `GET /jam-kerja` gagal **500** pada database tanpa baris jam kerja | Akses offset pada Collection **melempar galat** bila kunci tidak ada (`Collection::offsetGet`), sehingga `?->` tidak menolong — galatnya terjadi saat pengambilan kunci | Diganti `->get($kunci)` |
| Koreksi presensi admin **tidak pernah berefek** | `array_keys($model->getFillable())` menghasilkan indeks 0,1,2… sedangkan `getFillable()` sudah berupa daftar nama kolom, sehingga tidak ada kolom yang lolos | Memakai `getFillable()` langsung |
| Presensi `valid` bisa salah dikategori sebagai "luar radius" | Kategori disimpulkan dari `lokasi_id` kosong, padahal presensi valid selalu punya lokasi | Disimpulkan dari validasi `disetujui` + lokasi kosong |
| Seluruh unggahan multipart berisiko rusak | `api.ts` menyetel `Content-Type: application/json` sebagai header bawaan, yang ikut terkirim pada FormData sehingga boundary peramban tidak dipakai | Interceptor melepas `Content-Type` saat data berupa FormData |
| `drawRectangle` gagal | Tanda tangan Intervention v3 adalah `($x, $y, $init)`; lebar/tinggi diatur di dalam closure, bukan argumen terpisah | Disusun ulang sesuai API v3 |
| Relasi `Pegawai::lokasi()` meledak saat dipanggil | `BelongsToMany` dipakai tanpa diimpor (laten: tipe parameter baru diperiksa saat dipakai) | Impor ditambahkan |
| Halaman presensi menampilkan "Bukan hari kerja" saat permintaan gagal | Penanda hari diperiksa dengan `!data?.is_hari_kerja`, sehingga data yang tidak ada tampil seolah hari libur | Dijaga `data !== undefined` dan keadaan gagal dijelaskan apa adanya |

---

## K. Catatan Fase 4 (jurnal & presensi siswa)

Tanggal: 6 Oktober 2026. Cakupan: FR-JRN-01..10, BR-19..BR-23, BR-26, A-01, A-09, A-12.

| No | Keputusan | Alasan / dampak |
|---|---|---|
| K-59 | Sesi = **rentang jam ke**, bukan satu jam pelajaran. | FR-JRN-01 menuntut entri jadwal berurutan untuk plotting mapel dan hari yang sama digabung. Karena itu `jam_ke_mulai` dan `jam_ke_selesai` disimpan berdampingan, dan penggabungan hanya terjadi bila jam ke benar-benar `+1` DAN plotting mapelnya sama — dua mapel berbeda pada jam 1 dan 3 tidak ikut tergabung. Diuji keduanya. |
| K-60 | `jam_ke_selesai` diambil dari jadwal, bukan dari kiriman klien. | Sesi adalah fakta jadwal. Klien yang mengirim `jam_ke_selesai` berbeda diabaikan server; ada uji khusus untuk itu. |
| K-61 | BR-21 ditopang **dua lapis**: indeks unik database + terjemahan `QueryException`. | Lapisan layanan bisa dilewati oleh balapan dua permintaan; database tidak. Duplikat 1062 diterjemahkan menjadi 409 berpesan jelas ("muat ulang halaman"), bukan 500. |
| K-62 | BR-22: siswa di luar kelas **ditolak**, bukan diabaikan diam-diam. | Mengabaikan akan menyimpan jurnal yang tampak benar padahal ada siswa hilang dari presensi. Penolakan membuat ketidakcocokan terlihat saat itu juga. |
| K-63 | Daftar siswa disalin saat jurnal dibuat, tidak dihitung ulang saat dibaca. | BR-22 mengikat daftar pada tahun pelajaran jurnal; bila plotting diubah setelahnya, jurnal lama harus tetap menggambarkan kelas saat itu. |
| K-64 | Presensi siswa diganti **utuh** saat jurnal diubah. | Menyisakan baris lama yang tidak lagi dikirim akan membuat jumlah H/S/I/A tidak cocok dengan yang dilihat guru. |
| K-65 | KP-4.6 (Berhalangan) ditegakkan **di server**, bukan hanya disandikan di tampilan. | Endpoint membuat jurnal menolak 422 `BERHALANGAN` walaupun UI disembunyikan. `PengajuanService::berhalanganPada()` sengaja tidak menyaring jenis pengajuan, sesuai FR-IZN-07 bahwa **dinas** juga membuat sesi berhalangan. |
| K-66 | Kepala sekolah & wakasek **tidak** diberi akses ke endpoint `/jurnal`. | Matriks Bagian 2 memberi mereka `L` pada *laporan* jurnal (Fase 5), bukan pada endpoint jurnal. Memberi akses lebih longgar sekarang akan melanggar matriks. |
| K-67 | Admin boleh mengoreksi jurnal guru, koreksinya tercatat sebagai `AKSI_UBAH_JURNAL` dengan pelakunya. | Matriks `K**` menuntut koreksi admin tercatat di `audit_log`; memakai aksi yang sama menjaga jejaknya satu tempat. |
| K-68 | **Endpoint baru `GET /jurnal/kelas-wali`.** | Ditemukan lewat uji asap: halaman rekap wali kelas memakai master `/kelas`, yang menurut matriks hanya untuk admin/kepsek/wakasek — guru menerima **403**, sehingga halaman rekap pecah tepat pada pengguna yang dituju. Master data tidak boleh dilonggarkan demi satu halaman; endpoint khusus ini mengembalikan kelas wali milik guru (admin tetap menerima seluruh kelas tahun aktif). |
| K-69 | Foto jurnal tanpa watermark, disimpan di disk privat. | Berbeda dari foto presensi (BR-29 mewajibkan watermark karena membuktikan kehadiran). Foto kegiatan hanya dokumentasi; tetap privat dan disajikan lewat endpoint berpelindung, dan berkas yang gugur retensi dijawab 404 berpesan, bukan 500. |
| K-70 | **PDF laporan dikompresi normal + subsetting font** (Opsi A), dan isi PDF diuji dengan mengembang aliran (`teksPdf()`). | `compress => 0` lama membuat satu laporan kecil 1,6–2,3 MB — tak terpakai di internet lambat. Kini `compress => true` **dan** `isFontSubsettingEnabled => true` (hanya glif terpakai yang ditanam): `rekap-pegawai` 1.631.904 → **24.165 byte**, `jurnal/daftar` 2.310.285 → **23.077 byte**. Opsi B (kompresi berbeda saat `testing`) ditolak karena perilaku uji jadi tidak sama dengan produksi; Opsi C tidak perlu karena logo/TTD tidak ditanam berulang. Catatan: `isFontSubsettingEnabled` **harus** lewat `setOption()`, diabaikan bila hanya dikirim ke `output()`. |
| K-71 | Laporan Fase 5 **mengembalikan `pegawai_id` tersaring sebagai integer**, termasuk untuk peran pemantau. | `batasiPegawai(?int)` menerima nilai dari query string (selalu `string`), sehingga `pegawai_id` non-kosong meledak 500 `TypeError`. Sekarang setiap pemanggil melakukan cast eksplisit `(int)`. |
| K-72 | BR-24/BR-26 diuji dengan angka **persis**, bukan sekadar status 200. | Fixture menyiapkan `HariLibur`, `JamKerja.is_hari_kerja`, dan `PengajuanIzin` disetujui; hari libur/izin/belum-lewat tidak menambah alpa, hari lewat tanpa presensi & izin menambah alpa. Diuji juga alpa **tidak tersimpan** sebagai baris (jumlah `presensi_pegawai` tidak berubah). |
| K-73 | KP-5.5 (wali kelas) ditegakkan **di server** pada endpoint laporan tersendiri (`/laporan/kelas-wali/rekap-siswa`). | Kelas lain → 403, guru bukan wali → 403, admin bebas memilih kelas (tanpa `kelas_id` → 422 "pilih kelas"). Endpoint `/laporan/*` sengaja terpisah agar master `/kelas` tetap hanya untuk pemantau. |
| K-74 | Kepala sekolah & wakasek boleh **membaca laporan** jurnal (`L`) tetapi tetap **ditolak** pada endpoint `/jurnal` Fase 4. | Diuji eksplisit: `/laporan/jurnal/daftar` 200, `/jurnal/kelas-wali` 403 untuk keduanya — menjaga matriks Bagian 2 dan keputusan K-66. |
| K-75 | BR-36 ditegakkan di **satu tempat**: `Pengumuman::scopeTayang()` + `PengumumanService`. | TV (FR-TV-09/10), beranda (FR-PMN-02), dan landing (FR-LND-05) memakai penyaring yang sama, sehingga aturan "aktif DAN dalam rentang tanggal DAN jam AND target cocok" tidak pernah disalin ulang dan menyimpang. Pengumuman kedaluwarsa hanya berhenti tampil — barisnya tetap ada (diuji). |
| K-76 | Token TV disimpan **terpisah** di tabel `sesi_tv` (hash sha256), bukan di `personal_access_tokens`. | BR-32 menuntut token TV hanya berlaku pada endpoint `tv`. Memakai Sanctum lalu "menyaring" per rute membuka celah; tabel terpisah membuat penolakan bersifat struktural: middleware `tv` tidak mengenal token Sanctum, dan `auth:sanctum` tidak mengenal token TV. Diuji **dua arah** (`/tv/rekap` + `/auth/me` + `/pengumuman`). |
| K-77 | Kunci 5×/menit/IP memakai `RateLimiter` berkunci `tv-masuk:{ip}`, dan **percobaan benar menghapus penghitung**. | Tanpa `RateLimiter::clear()`, pengguna yang salah 4 kali lalu benar masih terkunci di percobaan berikutnya — mengganggu tanpa manfaat keamanan. Percobaan salah memakai jendela 60 detik, dan kode benar pun tetap ditolak selama jendela berjalan (diuji). |
| K-78 | BR-35: `buatUlangKode()` menyimpan kode baru **lalu** `cabutSemua()`. | Urutan ini mencegah sesi lama hidup dengan kode baru. Endpoint melaporkan `sesi_dicabut` dan `sisa_sesi` agar hasilnya dapat dibuktikan (uji: 1 → 0). |
| K-79 | BR-37 ditegakkan **lewat arsitektur**: `TvRekapService` memanggil `LaporanPresensiService::harian/rekapIzin` dan `LaporanJurnalService::kepatuhanJurnal`, tidak pernah menghitung dari tabel mentah. | Inilah yang membuat angka TV identik dengan laporan Fase 5. Ujinya **membandingkan angka** (`ringkasan` presensi, empat angka jurnal, `ringkasan` perizinan) dengan keluaran layanan laporan untuk tanggal yang sama — bukan sekadar memeriksa status 200. |
| K-80 | KP-6.6: cache **per tanggal** (`tv:rekap:{tanggal}`, 15 detik), bukan per token. | Banyak perangkat TV pada interval sama berbagi satu perhitungan. Diuji **tanpa mock** (kelas laporan bersifat `final`, sehingga Mockery tidak dapat memakainya): data diubah setelah perhitungan pertama, lalu permintaan dari **token TV lain** dibuktikan masih memakai hasil cache, dan tanggal lain dihitung sendiri. |
| K-81 | BR-33: respons `/tv/rekap` **membangun ulang bentuk terbatas** (inisial, nama, jam, status) alih-alih mengembalikan baris layanan apa adanya, dan alasan izin hanya muncul bila `tv_tampilkan_alasan_izin` aktif. | Baris `MonitoringPresensiService::harian()` memuat `nip`, `jarak_m`, `ada_foto`, `alasan_luar_radius`; semuanya dibuang. Diuji dengan memindai **seluruh nama kunci JSON** secara rekursif dan memastikan kunci terlarang benar-benar tidak ada. Avatar = huruf inisial dihitung server. |
| K-82 | Ringkasan presensi siswa di TV (H/S/I/A) dihitung dari `presensi_siswa` JOIN `jurnal` pada tanggal itu, **hanya jumlah** tanpa nama siswa. | Fase 5 tidak punya laporan agregat presensi siswa lintas kelas (hanya per kelas), sehingga angka ini tidak punya padanan laporan; sifatnya panel tambahan FR-TV-07, bukan kolom penilaian BR-37. Tidak ada satu pun nama siswa yang dikirim (BR-33). |
| K-83 | **BUG:** MariaDB 10.4 menolak `timestamp NOT NULL` tanpa nilai default → `SQLSTATE[42000] 1067 Invalid default value for 'kedaluwarsa_at'`. | Tabel `sesi_tv` memakai `dateTime` untuk `terakhir_aktif_at`, `kedaluwarsa_at`, dan `dicabut_pada`. Migrasi seluruh suite gagal sebelum diperbaiki, sehingga **seluruh** uji Fase 6 merah sekaligus dengan pesan yang menunjuk migrasi — bukan kode uji. |
| K-84 | **BUG:** `CarbonInterface::diffInDays()` di Carbon 3 mengembalikan nilai **bertanda** (−30, bukan 30). | Uji masa berlaku token TV ditulis ulang memakai `greaterThan(now()->addDays(29))`. Pelajarannya: jangan memakai asumsi Carbon 2 (`abs`) pada perbandingan masa berlaku. |

### Bug yang ditemukan uji Fase 6 (dicatat agar tidak terulang)

1. **`timestamp NOT NULL` tanpa default di MariaDB 10.4** (K-83) — membuat `create table sesi_tv` gagal dan **semua** uji Fase 6 merah; pesannya menunjuk migrasi, bukan uji.
2. **Tanda kurung `routes/api.php` tidak seimbang** setelah penyuntingan blok rute: `Route::prefix('laporan')->group()` tertinggal tanpa `);` sehingga Laravel melaporkan `Unclosed '(' on line 367`. Pint 299 berkas **tidak** memperbaikinya — satu-satunya tanda adalah seluruh rute gagal dimuat.
3. **`diffInDays()` bertanda di Carbon 3** (K-84).
4. **Uji `is_active` gagal massal akibat migrasi**, bukan akibat logika: memperbaiki satu baris migrasi menghijaukan 37 uji sekaligus. Baca pesan galat pertama sebelum menuduh kode uji.

### Bug yang ditemukan uji Fase 5 (dicatat agar tidak terulang)

1. **`batasiPegawai(): Argument #2 ($diminta) must be of type ?int, string given`** — `GET /laporan/presensi/rekap-pegawai?pegawai_id=<n>` sebagai guru/kepsek membalas **500**. Nilai dari query selalu `string`; diperbaiki dengan cast `(int)` di ketiga pemanggil (`rekapPegawai`, `rekapIzin`, `rekapLuarRadius`).
2. **PDF laporan terlalu gemuk** (lihat K-70) — akar masalahnya bukan gambar, melainkan `compress => 0` + font penuh DejaVu Sans ditanam tanpa subsetting.
3. **`LaporanJurnalService` punya impor tak terpakai** (`JamKerjaService`, `PresensiSiswa` sebagian) yang dibersihkan Pint.

### Bug yang ditemukan uji saat pengembangan (dicatat agar tidak terulang)

1. **`JurnalFoto` tanpa `$fillable`** padahal layanan memakai `create()` — menyimpan foto gagal 500. Anggapan "baris dibuat sistem, jadi tidak perlu fillable" keliru: `$fillable` melindungi dari pemetaan massal masukan pengguna, bukan dari kode sendiri.
2. **`ResponsDaftar::buat()` dipanggil dengan urutan argumen terbalik** sehingga daftar riwayat tidak akan pernah terbentuk benar.
3. **Helper uji Fase 4 memakai empat model tanpa impor** (`Jadwal`, `PlottingKelas`, `PresensiPegawai`, `Siswa`) — semua uji gagal dengan "Class not found".

### Yang perlu diketahui pengembang berikutnya

- Halaman isi jurnal menandai sesi lewat URL `:sesi` = `plottingMapelId-jamKeMulai` + query `?tanggal=`; penanda ini **dicocokkan ke jadwal server**, jadi halaman tidak pernah mengarang sesi (FR-JRN-06).
- `labelJamKe()` pada model dan `labelJamKe()` pada layanan sengaja ada di dua tempat: yang pertama untuk jurnal tersimpan, yang kedua untuk sesi yang belum tersimpan.

---

## M. Perbaikan Fase 5 lanjutan (temuan uji asap)

| No | Temuan | Akar masalah & perbaikan |
|---|---|---|
| K-85 | **Ekspor Excel terunduh bernama `.pdf`.** Pengguna mengira berkasnya rusak. | CORS hanya membuka header *safelisted* kepada JavaScript; `Content-Disposition` bukan salah satunya, sehingga frontend selalu gagal membaca nama berkas dari server dan jatuh ke nama cadangan berakhiran `.pdf`. Diperbaiki dengan `exposed_headers => ['Content-Disposition']` di `config/cors.php`. PDF tampak benar hanya karena nama cadangannya kebetulan `.pdf` — inilah sebabnya cacat ini tidak terlihat pada uji yang hanya memeriksa status 200. |
| K-86 | **Uji `LaporanEksporTest.php:221` (KP-5.2) flaky** — gagal 1 dari 2 run suite penuh, lulus pada run berikutnya dan lulus saat berkasnya dijalankan sendiri. | Dugaan: helper `teksPdf()` kadang tidak menemukan teks karena cara dompdf memecah aliran konten FlateDecode. BELUM diperbaiki. Perlu diperkuat agar CI tidak pernah merah tanpa sebab nyata. |
| K-87 | Subagen Fase 6 menjalankan `npm install` di `au-backend` (repo API-only) demi memenuhi perintah verifikasi `npm run build`. | Tidak merusak: `node_modules/` dan `public/build/` sudah terabaikan `.gitignore`, dan `package-lock.json` dihapus sehingga tidak masuk repo. Namun `au-backend` sebenarnya tidak memerlukan build aset frontend — perintah verifikasinya seharusnya hanya Pint + suite. |

---

## N. Fase 7 — tugas terjadwal retensi foto (BR-30)

| No | Keputusan | Alasan |
|---|---|---|
| K-88 | Perintah `presensi:bersihkan-foto` dibangun di atas `RetensiFotoService`, dipakai bersama oleh perintah artisan **dan** endpoint pembersihan manual admin. | Aturan "hanya tahun pelajaran `selesai` yang boleh dibersihkan" hidup di satu tempat: perintah/endpoint menyaring status lebih dulu, layanan tidak pernah memutuskan statusnya sendiri. `--kering`/`--dry-run` melaporkan berapa berkas **akan** dihapus tanpa menyentuh disk maupun kolom. |
| K-89 | **Lampiran foto jurnal IKUT dibersihkan**, meski catatan `SERAH-TERIMA-FASE-7` §1.1 menulis "foto jurnal tidak ikut terhapus". | BR-30 berbunyi "foto presensi **dan lampiran**", dan A-12 menegaskan "foto jurnal ... ikut aturan retensi". Kolom `jurnal_foto.foto_dihapus_pada` memang sudah disiapkan sejak Fase 4 justru untuk ini. Karena itu `jurnal_foto.foto_path` dibuat **nullable** (migrasi `2026_10_15_000001`) agar dapat diisi NULL sesuai BR-30; baris jurnal & presensi siswa tetap utuh. Bila pemilik menolak, cukup berhenti memanggil `bersihkanLampiranJurnal()`. Endpoint penyajian foto jurnal diberi penjagaan `foto_path === null` → 404 berpesan (bukan 500). |
| K-90 | **BUG temuan uji:** `Jurnal::factory()` membuat `tahun_pelajaran` bawaannya sendiri dengan nama default, sehingga satu uji yang menyiapkan tahun `selesai` bernama sama gagal `Duplicate entry '2025/2026' for key 'tahun_pelajaran_nama_unique'`. | Diperbaiki di berkas uji dengan memakai nama tahun yang berbeda dari default factory. Bukan cacat aplikasi, tetapi jebakan uji yang perlu dicatat. |
| K-91 | Bukti uji negatif: perlindungan tahun aktif **sengaja dilumpuhkan** (`isSelesai()` → `false`, filter `status` dihapus), lalu uji "tahun pelajaran AKTIF tidak pernah tersentuh" dijalankan → **MERAH** (`Unable to find a file or directory at path [...]`). Perlindungan dikembalikan dan uji hijau lagi. | Membuktikan uji itu benar-benar menguji perlindungan, bukan lulus palsu karena datanya kebetulan kosong. |
| K-92 | Jadwal berkala: `Schedule::command('presensi:bersihkan-foto')->dailyAt('01:30')->timezone('Asia/Jakarta')->withoutOverlapping()` di `routes/console.php`. | Dini hari agar tidak berebut dengan jam sibuk presensi pagi. `onOneServer()` sengaja tidak dipakai karena menuntut driver cache ber-atomic-lock. |
| K-93 | Endpoint pembersihan manual: `POST /api/v1/tahun-pelajaran/{id}/bersihkan-foto` di grup middleware `peran:admin`, menuntut `konfirmasi` bernilai benar (BR-31). | Matriks Bagian 2: hanya admin (K). Tahun pelajaran yang belum `selesai` ditolak **422** berkode `TAHUN_BELUM_SELESAI` (bukan 500) sehingga perlindungannya tidak bergantung pada UI. Ditambahkan ke grup rute yang sudah ada — tidak menduplikasi endpoint serupa (belum ada). |

---

## N. Temuan flaky suite uji (utang, belum diperbaiki)

Dua uji teramati gagal secara **bergantung urutan** pada suite penuh, lalu lulus saat berkasnya dijalankan sendiri. Keduanya menyangkut pemrosesan gambar/PDF:

| Uji | Gejala |
|---|---|
| `tests/Feature/Fase5/LaporanEksporTest.php:221` (KP-5.2) | Teks kop baru tidak ditemukan pada PDF hasil cetak ulang. Lulus saat berkas dijalankan sendiri. |
| `tests/Feature/Fase3/PresensiMasukTest.php:253` (BR-29) | Lebar foto hasil unggah > 800 px, padahal seharusnya diperkecil. Lulus 19/19 saat berkas dijalankan sendiri. |

**Pengukuran:** pada dua run suite penuh berturut-turut di database terpisah, hasilnya `1 failed / 354 passed` lalu `355 passed / 0 failed`. Jumlah uji naik karena pekerjaan Fase 7 masuk di antaranya. Jadi kegagalannya berpindah, bukan menetap — ini nondeterminisme, bukan regresi.

**Petunjuk akar masalah yang sudah ditemukan:**
1. `BerkasService` baris 89 membaca `foto_max_sisi_px` **dari pengaturan** (bisa 200–2000), bukan konstanta — jadi hasil pengecilan foto bergantung pada nilai pengaturan saat uji berjalan.
2. Uji `tests/Feature/Fase1/InfoSekolahTest.php:183` memang **mengubah** `foto_max_sisi_px` (menjadi 720) — bukti bahwa pengaturan ini disentuh uji.
3. **Hipotesis cache SUDAH DIUJI DAN DITOLAK.** Dugaan awal saya adalah nilai pengaturan bocor lewat `Cache::remember('pengaturan:semua')` di `PengaturanService` karena `RefreshDatabase` hanya me-reset database. Itu **salah**: `phpunit.xml` menetapkan `CACHE_STORE=array`, dan store array di-reset setiap uji karena aplikasi dibangun ulang per uji. Jadi nilai pengaturan selalu berasal dari database yang bersih — cache bukan penyebabnya.
4. Sisa kemungkinan yang belum diuji: (a) efek samping `Storage` pada disk sungguhan yang bocor antar uji (mis. uji yang menghapus berkas atau memakai `Storage::fake` tanpa dipulihkan), atau (b) kegagalan intermiten nyata pada pemrosesan gambar — yang bila benar berarti **cacat produk**, bukan sekadar uji: sesekali foto pengguna tersimpan tanpa diperkecil dan melampaui batas BR-29.

**Langkah perbaikan yang disarankan** (urut dari paling mungkin):
1. Jalankan `vendor/bin/pest --order-by=random` berulang kali untuk menemukan pasangan uji yang saling mengotori, lalu periksa uji yang menyentuh `Storage`/berkas.
2. Uji yang mengubah pengaturan atau menghapus berkas wajib memulihkan keadaannya.
3. Periksa apakah ada jalur **gagal-senyap** pada pengecilan gambar yang menyimpan gambar asli ketika pemrosesan gagal — bila ada, itu cacat produk yang harus diperbaiki (bukan hanya ujinya).
4. Baru setelah itu perkuat `teksPdf()` untuk uji KP-5.2.

**Cara memverifikasi terpisah tanpa mengganggu pekerjaan lain** (berguna karena database uji dipakai bersama): buat salinan `phpunit.xml` dengan `DB_DATABASE` berbeda, jalankan `artisan test -c phpunit.<nama>.xml`, lalu hapus salinan dan database itu. Jangan lupa `phpunit.xml` memakai `force="true"` sehingga variabel lingkungan biasa TIDAK dapat menimpanya.
