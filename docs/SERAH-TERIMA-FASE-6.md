# SERAH-TERIMA — Fase 6 (Pengumuman, Layar TV & Landing Page)

Status saat serah terima: **Fase 0–5 selesai, ter-verifikasi, ter-backup.**
Backend `au-backend` @ HEAD `feat(fase-5)`, CI hijau. Yang terbukti:

| | au-backend |
|---|---|
| Uji | **305 lulus / 1197 assertion** (Fase 4: 260/988; +45 uji / +209 assertion dari Fase 5) |
| Pint | PASS — 280 berkas |
| Rute API | **158** (Fase 4: 140) |
| PDF laporan | 1.631.904 → **24.334 byte** · 2.310.285 → **23.084 byte** |

---

## 1. Yang sudah siap dan TIDAK perlu dibangun ulang

| Fondasi | Letak | Dipakai untuk |
|---|---|---|
| Layanan laporan (angka otoritatif) | `app/Services/Laporan/LaporanPresensiService.php`, `LaporanJurnalService.php` | **KP-6.3/BR-37 — TV WAJIB memakai layanan ini**, jangan menghitung sendiri |
| Periode laporan | `app/Support/PeriodeLaporan.php` | Rentang tanggal TV "hari ini" |
| Dokumen resmi (kop, TTD, PDF, Excel) | `app/Support/DokumenPdf.php`, `app/Services/Laporan/DokumenResmiService.php` | FR-KOP-06 (dokumen cetak jadwal & daftar siswa) |
| Info sekolah | model `ProfilSekolah` + `PengaturanTtd`/`Penandatangan` | Sumber data landing page, TV, judul/ikon/footer (FR-SCH-02) |
| Otorisasi per peran | trait `MemakaiPegawai`, middleware `peran:` | Semua endpoint baru |
| `ResponsDaftar::buat($paginator, Resource::class)` | `app/Support/ResponsDaftar.php` | Daftar pengumuman & sesi TV |
| Helper uji | `tests/Pest.php`: `siapkanPeran()`, `buatPegawaiDenganAkun()`, `sebagaiAdmin()`, `siapkanJurnal()`, `siapkanPresensi()`, `profilSekolah()`, `tataTtd()`, `penandatangan()`, `teksPdf()` | Uji Fase 6 |
| Timezone otoritatif | `WaktuService` / `CarbonImmutable::now()`, `Asia/Jakarta` | `$this->travelTo()` bekerja di uji (BR-38) |
| Password seeding | `UserSeeder::PASSWORD_AWAL` = `Sipandu#2026` | Uji asap peramban |

**Landing page sudah ada** (`src/features/landing/LandingPage.tsx`) — cek dulu bagian mana dari 5.21 yang belum, jangan tulis ulang. **`/tv` sudah ada placeholder** — cek `src/app/router.tsx`.

---

## 2. Cakupan Fase 6

### 2.1 Pengumuman (5.20, `FR-PMN`)

- `FR-PMN-03` tayang bila `is_active` **dan** sekarang berada dalam rentang tanggal (dan jam bila diisi) — `BR-36`. Yang kedaluwarsa otomatis tidak tampil tetapi **tidak dihapus**.
- Target tampil: app / TV / landing (kolom target) — `BR-36`.
- KP-6.7 menguji tepat ini.

### 2.2 Layar TV (5.19, `FR-TV-01..17`)

- `FR-TV-03` setelah kode benar, server menerbitkan **token TV read-only** (masa berlaku default **30 hari**) yang disimpan perangkat TV sehingga tidak perlu memasukkan kode lagi setelah dinyalakan ulang.
- **`BR-32` — dua hal terpisah**: (a) kode salah berulang **5×/menit/IP** dikunci sementara; (b) token TV **hanya** berlaku untuk endpoint `tv`, tidak dapat dipakai ke API lain.
- `BR-35` — membuat ulang kode TV **mencabut semua sesi TV**.
- `FR-TV-16` pengaturan TV: aktif/nonaktif, lihat + buat ulang kode, izinkan NPSN, interval refresh, tema, skala font, tampilkan alasan izin, tampilkan ulang tahun, durasi rotasi panel, masa berlaku token, **daftar sesi TV aktif** (nama perangkat, terakhir aktif, IP) dengan tombol cabut, tombol **Pratinjau**.
- `FR-TV-17` + `BR-33` — TV **TIDAK** menampilkan: foto selfie presensi, koordinat, NIP, nomor HP, alasan sakit (kecuali opsi alasan aktif), data pribadi siswa. **Siswa hanya ditampilkan sebagai agregat.** Avatar pegawai = **huruf inisial**.
- KP-6.3 — tiga kolom (presensi, jurnal, perizinan) + header jam server + panel pengumuman + teks berjalan; dan **angkanya sama dengan laporan untuk tanggal yang sama**.
- KP-6.4 — diuji **pada isi respons API**, bukan pada tampilan.
- KP-6.5 — saat koneksi putus, data terakhir tetap tampil dengan indikator "terputus", pulih otomatis.
- KP-6.6 — banyak token TV memanggil `/tv/rekap` pada interval sama hanya memicu **satu perhitungan per 15 detik** (cache).
- KP-6.9 — diuji pada 1920×1080 dan 1366×768 tanpa elemen terpotong.

### 2.3 Landing page (5.21, `FR-LND`)

- `FR-LND-10` + `BR-34` — landing **tidak** menampilkan data pegawai, siswa, atau angka kehadiran.
- `FR-LND-11` — target performa.
- KP-6.8 — semua bagian 5.21 terisi dari **info sekolah**, dan tidak membocorkan data terlarang.

---

## 3. Aturan bisnis Fase 6 (mengikat)

| Kode | Isi |
|---|---|
| BR-32 | Kode TV salah 5×/menit/IP dikunci sementara; token TV hanya berlaku untuk endpoint `tv` |
| BR-33 | TV tidak memuat foto selfie, koordinat, NIP, nomor HP, alasan sakit (kecuali diaktifkan); siswa hanya agregat |
| BR-34 | Landing tidak memuat data pegawai, siswa, atau angka kehadiran |
| BR-35 | Membuat ulang kode TV mencabut semua sesi TV |
| BR-36 | Pengumuman tayang hanya dalam rentang tanggal/jam dan sesuai target tampil |
| BR-37 | Angka TV harus sama dengan laporan untuk tanggal yang sama |

---

## 4. Jebakan yang sudah terbukti menggigit

Semua di bawah ini pernah MENYEBABKAN bug nyata pada fase sebelumnya.

1. **BR-37 paling rawan.** Jangan menghitung ulang angka TV dari tabel mentah — panggil layanan laporan Fase 5. Kalau tidak, TV dan laporan akan berbeda dan KP-6.3 gagal. Uji dengan membandingkan angka TV dan angka laporan **untuk tanggal yang sama**, bukan sekadar memeriksa status 200.
2. **Cache 15 detik (KP-6.6) jangan mengunci balasan per-token** — cache-nya per-tanggal, sehingga banyak token berbagi satu hasil. Uji dengan menghitung jumlah pemanggilan layanan (spy/mock), bukan dengan mengukur waktu.
3. **Token TV bukan token Sanctum biasa.** Simpan terpisah (mis. tabel `sesi_tv` + hash token) dan pastikan middleware-nya hanya menerima token TV, sekaligus memastikan token TV **ditolak** di endpoint lain (BR-32, KP-6.2 — diuji dua arah).
4. **`compress => 0` pada dompdf pernah membuat PDF 2,3 MB.** Jangan ulangi; font subsetting + kompresi.
5. **Unggahan gambar** (logo sekolah di landing, gambar pengumuman): `Content-Type: application/json` bawaan interceptor di `src/lib/api.ts` merusak multipart. Interceptor sudah diperbaiki agar melepas header saat body `FormData` — jangan rusak lagi.
6. **Tanggal di frontend**: jangan `new Date('2026-07-06')` (dianggap UTC, harinya bergeser). Pakai `formatTanggalDari()` / `tanggalLokal()` di `src/lib/format.ts`.
7. **`$collection[$kunci]` melempar galat** bila kunci tidak ada — pakai `->get()`. Sering muncul saat merotasi panel TV.
8. **`getFillable()` sudah berupa daftar** — `array_keys($model->getFillable())` selalu salah.
9. **Model tanpa `$fillable` gagal saat `create()`.**
10. **`ResponsDaftar::buat()` = `($paginator, Resource::class)`** — mudah terbalik.
11. **Jangan `groupBy` pada kolom non-PK**; konvensi proyek pakai `DISTINCT`.
12. **Jangan `ob_end_clean()` manual** sebelum `response()->download()`.
13. **Nilai `0.0` lewat JSON menjadi `0`** — jangan `toBe(0.0)`. Dan `filter()` tanpa callback ikut membuang `0`.
14. **Verifikasi pakai perintah kanonik TANPA pipa grep.** Suite PHP tidak pernah memenuhi penanda bukti kanonik walau lulus — laporkan angkanya eksplisit.

## 5. Jebakan alat (bukan kode)

- **Satu agen tidak sanggup menyelesaikan satu fase penuh** dalam batas 50 panggilan. Pecah per repo (backend/frontend) dan per pekerjaan (tulis / uji+commit), dan beri tahu anak agen keadaan yang sudah terverifikasi supaya ia tidak membuang panggilan untuk eksplorasi.
- **Selalu verifikasi laporan anak agen.** Pada Fase 5, dua anak agen sama-sama berhenti sebelum menjalankan suite penuh dan commit; kodenya benar, tetapi klaim "selesai" tidak dapat dipercaya tanpa pemeriksaan sendiri.

---

## 6. Di luar cakupan (JANGAN dibuat)

Notifikasi WhatsApp/SMS/email otomatis; integrasi Dapodik; penilaian/rapor; keuangan; akun siswa; aplikasi native; SSR/SEO; layar TV interaktif atau kontrol jarak jauh (**TV hanya tampilan baca**); mode gelap di aplikasi (hanya TV yang bertema gelap); multi-sekolah; pengenalan wajah; guru pengganti/team teaching.
