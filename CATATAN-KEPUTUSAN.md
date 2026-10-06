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
