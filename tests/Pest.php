<?php

declare(strict_types=1);

use App\Models\HariLibur;
use App\Models\Jadwal;
use App\Models\JamKerja;
use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\LokasiPresensi;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\Penandatangan;
use App\Models\PengajuanIzin;
use App\Models\PengaturanTtd;
use App\Models\PlottingKelas;
use App\Models\PlottingMapel;
use App\Models\PolaJam;
use App\Models\PolaJamHari;
use App\Models\PresensiPegawai;
use App\Models\ProfilSekolah;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Siswa;
use App\Models\SlotJam;
use App\Models\TahunPelajaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Intervention\Image\ImageManager;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Konfigurasi Pest (3.2)
|--------------------------------------------------------------------------
| Setiap uji fitur memakai RefreshDatabase pada database uji terpisah
| (`sipandu_test`, lihat phpunit.xml) sehingga data pengembangan tidak tersentuh.
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

/**
 * Membuat pegawai contoh (guru atau struktural) beserta akun login dan perannya.
 */
function buatPegawaiDenganAkun(
    string $jenis = Pegawai::JENIS_GURU,
    array $atributPegawai = [],
    array $peranTambahan = [],
): User {
    $pegawai = Pegawai::factory()->create(array_merge(['jenis_pegawai' => $jenis], $atributPegawai));

    $kode = $jenis === Pegawai::JENIS_GURU ? Role::GURU : Role::PEGAWAI_STRUKTURAL;

    /** @var User $user */
    $user = User::factory()->create([
        'pegawai_id' => $pegawai->id,
        'name' => $pegawai->nama,
        'username' => $pegawai->nip,
    ]);

    $user->roles()->sync(
        Role::query()->whereIn('kode', array_merge([$kode], $peranTambahan))->pluck('id')->all()
    );

    return $user->fresh(['roles', 'pegawai']);
}

/**
 * Pengguna tanpa data pegawai (mis. admin, FR-SEC-03).
 */
function buatPengguna(array $kodePeran = [Role::ADMIN], array $atribut = []): User
{
    /** @var User $user */
    $user = User::factory()->create($atribut);

    $user->roles()->sync(
        Role::query()->whereIn('kode', $kodePeran)->pluck('id')->all()
    );

    return $user->fresh(['roles', 'pegawai']);
}

/**
 * Akun admin (tanpa data pegawai) — peran dengan hak kelola penuh.
 */
function sebagaiAdmin(array $atribut = []): User
{
    return buatPengguna([Role::ADMIN], $atribut);
}

/**
 * Akun kepala sekolah — boleh melihat master data, tidak boleh mengubahnya.
 */
function sebagaiKepsek(): User
{
    return buatPengguna([Role::KEPALA_SEKOLAH]);
}

/**
 * Akun wakasek kurikulum — boleh melihat master data, tidak boleh mengubahnya.
 */
function sebagaiWakasek(): User
{
    return buatPengguna([Role::WAKASEK_KURIKULUM]);
}

/**
 * Tahun pelajaran aktif beserta dua semester (semester ganjil aktif).
 */
function tahunAktif(): TahunPelajaran
{
    /** @var TahunPelajaran $tahun */
    $tahun = TahunPelajaran::factory()->aktif()->create();

    return $tahun->load('semester');
}

/**
 * Menyiapkan berkas Excel sementara untuk uji import.
 *
 * @param  array<int, string>  $judul
 * @param  array<int, array<int, mixed>>  $baris
 */
function berkasExcel(array $judul, array $baris, string $nama = 'data.xlsx'): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $kolom = 'A';
    foreach ($judul as $teks) {
        $sheet->setCellValue($kolom.'1', $teks);
        $kolom++;
    }

    $nomor = 1;
    foreach ($baris as $isiBaris) {
        $nomor++;
        $kolom = 'A';
        foreach ($isiBaris as $nilai) {
            $sheet->setCellValue($kolom.$nomor, $nilai);
            $kolom++;
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'sipandu_uji_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile(
        $path,
        $nama,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

/**
 * Mengisi seluruh peran bawaan (Bagian 2) pada database uji.
 */
function siapkanPeran(): void
{
    foreach (Role::DAFTAR as $kode => $nama) {
        Role::updateOrCreate(['kode' => $kode], ['nama' => $nama]);
    }
}

/*
|--------------------------------------------------------------------------
| Helper Fase 2 — plotting & jadwal
|--------------------------------------------------------------------------
*/

/**
 * Semester yang aktif pada sebuah tahun pelajaran (dibuat bila belum ada).
 */
function semesterAktif(?TahunPelajaran $tahun = null): Semester
{
    $tahun ??= tahunAktif();

    $semester = $tahun->semester()->where('is_active', true)->first();

    return $semester ?? $tahun->semester()->create([
        'jenis' => 'ganjil',
        'tanggal_mulai' => $tahun->tanggal_mulai,
        'is_active' => true,
    ]);
}

/**
 * Semester genap pada sebuah tahun pelajaran.
 * Memakai yang sudah ada: `tahunAktif()` sudah membuat ganjil + genap, sehingga
 * membuat genap baru akan melanggar UQ (tahun_pelajaran_id, jenis).
 */
function semesterGenap(TahunPelajaran $tahun): Semester
{
    return $tahun->semester()->where('jenis', 'genap')->first()
        ?? $tahun->semester()->create([
            'jenis' => 'genap',
            'tanggal_mulai' => $tahun->tanggal_selesai,
            'is_active' => false,
        ]);
}

/**
 * Mengirim presensi masuk sebagai pengguna tertentu (multipart, karena ada berkas).
 *
 * @param  array<string, mixed>  $tambahan
 */
function kirimPresensi(User $user, array $tambahan = [], ?UploadedFile $foto = null)
{
    $muatan = array_merge([
        'foto' => $foto ?? fotoUji(),
        'lat' => -7.8654000,
        'lng' => 111.4650000,
        'akurasi_m' => 10,
    ], $tambahan);

    return test()->actingAs($user)->post('/api/v1/presensi/masuk', $muatan);
}

/** Mengirim presensi pulang sebagai pengguna tertentu. */
function kirimPulang(User $user, array $tambahan = [], ?UploadedFile $foto = null)
{
    $muatan = array_merge([
        'foto' => $foto ?? fotoUji(),
        'lat' => -7.8654000,
        'lng' => 111.4650000,
        'akurasi_m' => 10,
    ], $tambahan);

    return test()->actingAs($user)->post('/api/v1/presensi/pulang', $muatan);
}

/**
 * Senin, 5 Oktober 2026 — hari kerja acuan uji presensi (jam masuk 07:00).
 * Dipakai bersama agar tidak ada konstanta tingkat berkas yang bisa bentrok
 * antarberkas uji.
 */
function seninUji(): string
{
    return '2026-10-05';
}

/** Sabtu, 10 Oktober 2026 — hari bukan hari kerja. */
function sabtuUji(): string
{
    return '2026-10-10';
}

/**
 * Foto uji: JPEG sungguhan (bukan berkas kosong) berisi derau agar ukuran
 * berkasnya realistis sehingga uji batas BR-29 bermakna.
 */
function fotoUji(int $lebar = 640, int $tinggi = 480): UploadedFile
{
    $gambar = ImageManager::gd()->create($lebar, $tinggi);

    /*
     * Citra uji WAJIB deterministik.
     *
     * Versi sebelumnya memakai random_int(), sehingga entropi gambar berubah tiap
     * run. Karena uji BR-29 menuntut hasil kompresi <= 150 KB pada citra ini,
     * gambarnya kadang LOLOS dan kadang GAGAL tanpa satu baris kode pun berubah —
     * flaky, dan sempat membuat suite penuh merah sekali lalu hijau pada run
     * berikutnya.
     *
     * Deret acak-semu berbenih tetap menggantikannya: gambarnya tetap "ramai"
     * (kasus terberat untuk kompresi, sehingga ujinya tetap bermakna dan tidak
     * jadi lulus palsu), tetapi hasilnya identik setiap kali.
     */
    $benih = 20261006;
    $acak = function (int $min, int $maks) use (&$benih): int {
        $benih = ($benih * 1103515245 + 12345) & 0x7FFFFFFF;

        return $min + ($benih % ($maks - $min + 1));
    };

    /*
     * Jumlah kotak proporsional terhadap luas. Ini disengaja: uji BR-29 mengunggah
     * citra 1600x1200 dan menuntut hasil kompresi <= 150 KB, jadi citra ujinya harus
     * benar-benar sulit dimampatkan. Dengan 140 kotak tetap, sumbernya hanya ~85 KB —
     * sudah di bawah batas, sehingga uji itu lulus tanpa pernah menguji kompresinya.
     * Pada kerapatan ini, 1600x1200 menghasilkan sumber ~509 KB (3,4x batas) dan tetap
     * di bawah batas unggah 1 MB, sedangkan citra kecil tetap ringan.
     */
    $jumlah = max(60, intdiv($lebar * $tinggi, 640));

    for ($i = 0; $i < $jumlah; $i++) {
        $x = $acak(0, max(1, $lebar - 60));
        $y = $acak(0, max(1, $tinggi - 60));

        $gambar->drawRectangle($x, $y, function ($kotak) use ($acak): void {
            $kotak->size($acak(10, 60), $acak(10, 60));
            $kotak->background(sprintf('rgba(%d,%d,%d,0.8)', $acak(0, 255), $acak(0, 255), $acak(0, 255)));
        });
    }

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sipandu-foto-'.uniqid().'.jpg';
    file_put_contents($path, (string) $gambar->toJpeg(90));

    return new UploadedFile($path, 'selfie.jpg', 'image/jpeg', null, true);
}

/**
 * Rangkaian Fase 3: pegawai guru dengan akun, satu lokasi default aktif,
 * dan jam kerja Senin–Jumat 07:00–15:00 (sabtu/minggu libur).
 *
 * Titik acuan: SMK (lintang -7.8654, bujur 111.4650) dengan radius 150 m.
 *
 * @return array{pegawai: Pegawai, user: User, lokasi: LokasiPresensi, koordinat: array{lat: float, lng: float, luar: array{lat: float, lng: float}}}
 */
function siapkanPresensi(int $radius = 150): array
{
    $lokasi = LokasiPresensi::factory()->default()->create([
        'nama' => 'SMK Uji',
        'latitude' => -7.8654000,
        'longitude' => 111.4650000,
        'radius_m' => $radius,
    ]);

    foreach (range(1, 7) as $hari) {
        $kerja = $hari <= 5;

        JamKerja::factory()->create([
            'jenis_pegawai' => Pegawai::JENIS_GURU,
            'hari' => $hari,
            'is_hari_kerja' => $kerja,
            'buka_presensi' => $kerja ? '06:30:00' : null,
            'jam_masuk' => $kerja ? '07:00:00' : null,
            'jam_pulang' => $kerja ? '15:00:00' : null,
        ]);
    }

    $user = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    return [
        'pegawai' => $user->pegawai,
        'user' => $user,
        'lokasi' => $lokasi,
        'koordinat' => [
            // Di dalam radius: ±10 m dari titik lokasi.
            'lat' => -7.8654000,
            'lng' => 111.4650000,
            // Jauh di luar radius (sekitar 4,4 km).
            'luar' => ['lat' => -7.9000000, 'lng' => 111.4750000],
        ],
    ];
}

/**
 * Rangkaian lengkap untuk uji Fase 2: tahun pelajaran aktif, semester aktif, jurusan,
 * guru, kelas, mapel, dan plotting mapel yang sudah konsisten satu sama lain.
 *
 * @return array{tahun: TahunPelajaran, semester: Semester, jurusan: Jurusan, guru: Pegawai, kelas: Kelas, mapel: Mapel, plotting: PlottingMapel}
 */
function siapkanAkademik(?TahunPelajaran $tahun = null, string $tingkat = 'X', array $atributKelas = []): array
{
    $tahun ??= tahunAktif();
    $semester = semesterAktif($tahun);
    $jurusan = Jurusan::factory()->create();
    $guru = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $mapel = Mapel::factory()->create();

    $kelas = Kelas::factory()->create(array_merge([
        'tahun_pelajaran_id' => $tahun->id,
        'tingkat' => $tingkat,
        'jurusan_id' => $jurusan->id,
    ], $atributKelas));

    $plotting = PlottingMapel::factory()->create([
        'semester_id' => $semester->id,
        'pegawai_id' => $guru->id,
        'mapel_id' => $mapel->id,
        'kelas_id' => $kelas->id,
        'jp_per_minggu' => 4,
    ]);

    return compact('tahun', 'semester', 'jurusan', 'guru', 'kelas', 'mapel', 'plotting');
}

/**
 * Memasang pola jam beserta slot pelajaran 1..N untuk hari-hari tertentu.
 * Dipakai uji jadwal: BR-08 menuntut slot berasal dari pola jam hari terkait.
 *
 * @param  list<int>  $hari
 * @return array{pola: PolaJam, hari: list<int>, slot: Collection<int, SlotJam>}
 */
function pasangPolaJam(Semester $semester, array $hari = [1], int $jumlahJp = 4): array
{
    $pola = PolaJam::factory()->create(['semester_id' => $semester->id, 'nama' => 'Pola Uji']);

    foreach ($hari as $h) {
        PolaJamHari::create(['pola_jam_id' => $pola->id, 'semester_id' => $semester->id, 'hari' => $h]);
    }

    $slot = collect();
    for ($i = 1; $i <= $jumlahJp; $i++) {
        $mulai = sprintf('%02d:00', 6 + $i);
        $selesai = sprintf('%02d:00', 7 + $i);

        $slot->push(SlotJam::create([
            'pola_jam_id' => $pola->id,
            'urutan' => $i,
            'tipe' => SlotJam::PELAJARAN,
            'label' => "Jam ke-{$i}",
            'jam_mulai' => $mulai,
            'jam_selesai' => $selesai,
            'jam_ke' => $i,
        ]));
    }

    // Slot istirahat dipakai menguji BR-08 (hanya slot pelajaran yang boleh dijadwalkan).
    $slot->push(SlotJam::create([
        'pola_jam_id' => $pola->id,
        'urutan' => $jumlahJp + 1,
        'tipe' => SlotJam::ISTIRAHAT,
        'label' => 'Istirahat',
        'jam_mulai' => sprintf('%02d:00', 7 + $jumlahJp),
        'jam_selesai' => sprintf('%02d:30', 7 + $jumlahJp),
        'jam_ke' => null,
    ]));

    return ['pola' => $pola, 'hari' => $hari, 'slot' => $slot];
}

/**
 * Rangkaian uji Fase 4 (jurnal & presensi siswa).
 *
 * Membentuk: tahun+semester aktif, guru beserta akun login, satu plotting mapel,
 * jadwal Senin pada jam ke-1..3 untuk plotting yang SAMA (karena itu menjadi satu
 * sesi gabungan menurut FR-JRN-01), siswa aktif yang terplot di kelas itu (BR-22),
 * dan opsional presensi masuk sesuai BR-19.
 *
 * @param  list<int>  $jamKe  jam ke yang dijadwalkan berturut-turut
 * @return array<string, mixed>
 */
function siapkanJurnal(bool $denganPresensi = true, array $jamKe = [1, 2, 3], ?string $validasi = null): array
{
    $akademik = siapkanAkademik();
    $semester = $akademik['semester'];
    $guru = $akademik['guru'];
    $plotting = $akademik['plotting'];
    $kelas = $akademik['kelas'];
    $tahun = $akademik['tahun'];

    /** @var User $user */
    $user = User::factory()->create([
        'pegawai_id' => $guru->id,
        'name' => $guru->nama,
        'username' => $guru->nip,
    ]);

    $user->roles()->sync(Role::query()->where('kode', Role::GURU)->pluck('id')->all());

    // Minimal 4 slot supaya addJadwal() dapat menambah jam lain pada uji penggabungan.
    $pola = pasangPolaJam($semester, [1], max(4, max($jamKe)));

    foreach ($jamKe as $jp) {
        Jadwal::factory()->create([
            'semester_id' => $semester->id,
            'hari' => 1,
            'slot_jam_id' => $pola['slot']->firstWhere('jam_ke', $jp)?->id,
            'plotting_mapel_id' => $plotting->id,
            'pegawai_id' => $guru->id,
            'kelas_id' => $kelas->id,
        ]);
    }

    $siswa = collect();

    for ($i = 1; $i <= 4; $i++) {
        /** @var Siswa $s */
        $s = Siswa::factory()->create(['status' => Siswa::STATUS_AKTIF]);

        PlottingKelas::factory()->create([
            'tahun_pelajaran_id' => $tahun->id,
            'kelas_id' => $kelas->id,
            'siswa_id' => $s->id,
        ]);

        $siswa->push($s);
    }

    $presensi = null;

    if ($denganPresensi) {
        $presensi = PresensiPegawai::factory()->create([
            'pegawai_id' => $guru->id,
            'tanggal' => seninUji(),
            'semester_id' => $semester->id,
            'masuk_validasi' => $validasi ?? PresensiPegawai::VALID,
        ]);
    }

    return [
        ...$akademik,
        'user' => $user->fresh(['roles', 'pegawai']),
        'siswa' => $siswa,
        'pola' => $pola,
        'presensi' => $presensi,
    ];
}

/**
 * Menambah satu entri jadwal pada jam ke tertentu untuk rangkaian `siapkanJurnal()`.
 * Dipakai menguji penggabungan sesi (FR-JRN-01) dengan plotting mapel berbeda.
 */
function addJadwal(array $rangkaian, int $plottingMapelId, int $jamKe): Jadwal
{
    return Jadwal::factory()->create([
        'semester_id' => $rangkaian['semester']->id,
        'hari' => 1,
        'slot_jam_id' => $rangkaian['pola']['slot']->firstWhere('jam_ke', $jamKe)?->id,
        'plotting_mapel_id' => $plottingMapelId,
        'pegawai_id' => $rangkaian['guru']->id,
        'kelas_id' => $rangkaian['kelas']->id,
    ]);
}

/**
 * Badan permintaan untuk membuat jurnal dari rangkaian `siapkanJurnal()`.
 *
 * @param  list<Siswa>  $siswa
 * @return array<string, mixed>
 */
function badanJurnal(array $rangkaian, array $siswa = [], array $ganti = []): array
{
    $presensi = [];

    foreach ($siswa as $s) {
        $presensi[(string) $s->id] = ['status' => 'H'];
    }

    return array_merge([
        'semester_id' => $rangkaian['semester']->id,
        'plotting_mapel_id' => $rangkaian['plotting']->id,
        'tanggal' => seninUji(),
        'jam_ke_mulai' => 1,
        'materi' => 'Materi uji jurnal',
        'kegiatan' => 'Kegiatan pembelajaran uji.',
        'presensi' => $presensi,
    ], $ganti);
}

/*
|--------------------------------------------------------------------------
| Helper Fase 5 — laporan & dokumen resmi
|--------------------------------------------------------------------------
*/

/**
 * Memasang jam kerja Senin–Jumat sebagai hari kerja untuk satu jenis pegawai
 * (sabtu & minggu libur). Dipakai uji BR-24 (alpa) dan BR-26 (kepatuhan jurnal)
 * yang membutuhkan penanda `is_hari_kerja` tanpa memerlukan rangkaian presensi.
 */
function pasangJamKerja(string $jenisPegawai = Pegawai::JENIS_GURU): void
{
    foreach (range(1, 7) as $hari) {
        $kerja = $hari <= 5;

        JamKerja::factory()->create([
            'jenis_pegawai' => $jenisPegawai,
            'hari' => $hari,
            'is_hari_kerja' => $kerja,
            'buka_presensi' => $kerja ? '06:30:00' : null,
            'jam_masuk' => $kerja ? '07:00:00' : null,
            'jam_pulang' => $kerja ? '15:00:00' : null,
        ]);
    }
}

/** Menandai satu tanggal (atau rentang) sebagai hari libur pada tahun pelajaran. */
function tandaiLibur(TahunPelajaran $tahun, string $dari, ?string $sampai = null, string $keterangan = 'Libur Uji'): HariLibur
{
    return HariLibur::factory()->create([
        'tahun_pelajaran_id' => $tahun->id,
        'tanggal_mulai' => $dari,
        'tanggal_selesai' => $sampai ?? $dari,
        'keterangan' => $keterangan,
    ]);
}

/**
 * Mengajukan izin/sakit/cuti/dinas yang sudah disetujui untuk seorang pegawai.
 */
function setujuiIzin(
    Pegawai $pegawai,
    string $dari,
    ?string $sampai = null,
    string $jenis = PengajuanIzin::JENIS_IZIN,
    string $status = PengajuanIzin::STATUS_DISETUJUI,
): PengajuanIzin {
    return PengajuanIzin::factory()->create([
        'pegawai_id' => $pegawai->id,
        'jenis' => $jenis,
        'tanggal_mulai' => $dari,
        'tanggal_selesai' => $sampai ?? $dari,
        'status' => $status,
    ]);
}

/** Profil sekolah tunggal beserta kop surat dasarnya. */
function profilSekolah(array $atribut = []): ProfilSekolah
{
    return ProfilSekolah::factory()->create(array_merge([
        'nama_sekolah' => 'SMK Uji SIPANDU',
        'kop_baris1' => 'PEMERINTAH PROVINSI JAWA TIMUR',
        'kop_baris2' => 'DINAS PENDIDIKAN',
        'kop_baris3' => 'SMK UJI SIPANDU',
    ], $atribut));
}

/** Tata letak tanda tangan tunggal (FR-KOP-04). */
function tataTtd(array $atribut = []): PengaturanTtd
{
    return PengaturanTtd::query()->create(array_merge([
        'kota_penetapan' => 'Surabaya',
        'mode_tanggal' => 'otomatis',
        'posisi' => 'kanan',
        'tampilkan_mengetahui' => false,
    ], $atribut));
}

/** Penandatangan dokumen resmi (FR-KOP-03). */
function penandatangan(array $atribut = []): Penandatangan
{
    return Penandatangan::query()->create(array_merge([
        'jabatan' => 'Kepala Sekolah',
        'nama' => 'Drs. Contoh Kepala',
        'nip' => '197001012000031001',
        'urutan' => 1,
        'is_default' => true,
        'is_active' => true,
    ], $atribut));
}

/**
 * Membaca teks dari berkas PDF yang SUDAH dikompresi (Opsi A, K-70).
 *
 * Aliran FlateDecode dikembangkan memakai zlib, lalu literal teks PDF
 * `(...)` diambil dan pasangan byte UTF-16BE (0x00 di antara ASCII) dibuang
 * sehingga kata seperti "REKAP" dapat dicari sebagai teks biasa. Dipakai uji
 * untuk membuktikan dokumen yang BENAR-BENAR dibuat memuat judul, periode,
 * tanggal cetak, dan nama penandatangan.
 */
function teksPdf(string $pdf): string
{
    $isi = '';
    $pos = 0;

    /*
     * Aliran dipindai lewat offset byte, BUKAN regex.
     *
     * Versi sebelumnya memakai `/stream\r?\n(.*?)\r?\nendstream/s` atas data biner
     * berukuran puluhan kilobyte. Regex malas seperti itu dapat menabrak batas
     * backtrack PCRE dan gagal DIAM-DIAM (nilainya `false`, tidak diperiksa),
     * sementara ukuran PDF berubah sedikit tiap pencetakan karena ia memuat tanggal
     * pembuatan. Akibatnya ekstraksi kadang utuh dan kadang kosong — uji KP-5.1
     * flaky: gagal sekali lalu lulus pada run berikutnya tanpa kode berubah.
     */
    while (($awal = strpos($pdf, 'stream', $pos)) !== false) {
        // 'endstream' juga memuat kata 'stream'; jangan memulai aliran di tengahnya.
        if (substr($pdf, max(0, $awal - 3), 3) === 'end') {
            $pos = $awal + 6;

            continue;
        }

        $mulai = $awal + 6;

        if (substr($pdf, $mulai, 2) === "\r\n") {
            $mulai += 2;
        } elseif (substr($pdf, $mulai, 1) === "\n") {
            $mulai += 1;
        }

        $akhir = strpos($pdf, 'endstream', $mulai);

        if ($akhir === false) {
            break;
        }

        $aliran = rtrim(substr($pdf, $mulai, $akhir - $mulai), "\r\n");

        $urai = @gzuncompress($aliran);

        if ($urai === false) {
            $urai = @gzinflate($aliran);
        }

        if ($urai !== false) {
            $isi .= $urai."\n";
        }

        $pos = $akhir + 9;
    }

    return ambilLiteralTeksPdf($isi);
}

/**
 * Mengambil seluruh literal teks `( ... )` dari aliran PDF yang sudah dikembangkan.
 *
 * Ditelusuri karakter per karakter, bukan dengan regex, karena isinya biner dan
 * literal dapat memuat tanda kurung bersarang maupun escape (`\(`, `\)`, `\\`).
 * Byte 0x00 dibuang karena dompdf menulis teksnya sebagai UTF-16BE, sehingga setiap
 * huruf ASCII didahului satu byte kosong.
 */
function ambilLiteralTeksPdf(string $isi): string
{
    $teks = '';
    $panjang = strlen($isi);
    $i = 0;

    while ($i < $panjang) {
        if ($isi[$i] !== '(') {
            $i++;

            continue;
        }

        $dalam = 1;
        $buf = '';
        $i++;

        while ($i < $panjang && $dalam > 0) {
            $c = $isi[$i];

            if ($c === '\\') {
                $berikut = $isi[$i + 1] ?? '';

                if ($berikut === '(' || $berikut === ')' || $berikut === '\\') {
                    $buf .= $berikut;
                }

                $i += 2;

                continue;
            }

            if ($c === '(') {
                $dalam++;
                $buf .= $c;
                $i++;

                continue;
            }

            if ($c === ')') {
                $dalam--;

                if ($dalam === 0) {
                    $i++;

                    break;
                }

                $buf .= $c;
                $i++;

                continue;
            }

            $buf .= $c;
            $i++;
        }

        $teks .= str_replace("\x00", '', $buf).' ';
    }

    return $teks;
}
