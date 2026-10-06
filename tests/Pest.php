<?php

declare(strict_types=1);

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\PolaJam;
use App\Models\PolaJamHari;
use App\Models\Role;
use App\Models\Semester;
use App\Models\SlotJam;
use App\Models\TahunPelajaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
