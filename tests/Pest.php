<?php

declare(strict_types=1);

use App\Models\Pegawai;
use App\Models\Role;
use App\Models\TahunPelajaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
