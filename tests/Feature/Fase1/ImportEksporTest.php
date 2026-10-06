<?php

declare(strict_types=1);

use App\Models\Pegawai;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\User;
use App\Services\ExcelService;
use App\Services\ImportMasterService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

beforeEach(function (): void {
    siapkanPeran();
});

/** KP-1.3 — import melaporkan baris gagal tanpa menggagalkan baris valid. */
it('mengimport baris valid walau ada baris gagal (KP-1.3)', function (): void {
    $admin = sebagaiAdmin();
    Siswa::factory()->create(['nis' => '1111111']);

    $berkas = berkasExcel(
        ImportMasterService::JUDUL_SISWA,
        [
            ['2222222', '2222222222', 'Siswa Kedua', 'L', 'Blitar', '2010-05-17', 2024, 'aktif'],
            ['1111111', '3333333333', 'NIS Ganda', 'P', 'Blitar', '2010-06-18', 2024, 'aktif'],
            ['4444444', '4444444444', 'Siswa Keempat', 'P', 'Kediri', '2011-01-02', 2024, 'aktif'],
        ]
    );

    $respons = $this->actingAs($admin)->postJson('/api/v1/siswa/import', ['berkas' => $berkas]);

    $respons->assertOk()
        ->assertJsonPath('data.total_baris', 3)
        ->assertJsonPath('data.berhasil', 2)
        ->assertJsonPath('data.gagal', 1);

    // Baris 1 = judul, sehingga NIS ganda berada pada baris 3.
    expect($respons->json('data.baris_gagal.0.baris'))->toBe(3)
        ->and($respons->json('data.baris_gagal.0.pesan'))->toContain('NIS');

    expect(Siswa::whereIn('nis', ['2222222', '4444444'])->count())->toBe(2)
        ->and(Siswa::where('nis', '1111111')->count())->toBe(1);
});

/** Masalah klasik PhpSpreadsheet: tanggal tersimpan sebagai angka serial (mis. 40571). */
it('mengubah angka serial Excel menjadi tanggal yang benar', function (): void {
    $admin = sebagaiAdmin();
    $serial = ExcelDate::PHPToExcel(new DateTime('2010-05-17'));

    $berkas = berkasExcel(
        ImportMasterService::JUDUL_SISWA,
        [['5555555', '5555555555', 'Siswa Serial', 'L', 'Blitar', $serial, 2024, 'aktif']]
    );

    $this->actingAs($admin)->postJson('/api/v1/siswa/import', ['berkas' => $berkas])->assertOk();

    expect(Siswa::where('nis', '5555555')->first()?->tanggal_lahir?->format('Y-m-d'))->toBe('2010-05-17');
});

it('mengubah tanggal berbahasa Indonesia menjadi tanggal yang benar', function (): void {
    $admin = sebagaiAdmin();

    $berkas = berkasExcel(
        ImportMasterService::JUDUL_SISWA,
        [['6666666', '6666666666', 'Siswa Teks', 'P', 'Blitar', '17 Mei 2010', 2024, 'aktif']]
    );

    $this->actingAs($admin)->postJson('/api/v1/siswa/import', ['berkas' => $berkas])->assertOk();

    expect(Siswa::where('nis', '6666666')->first()?->tanggal_lahir?->format('Y-m-d'))->toBe('2010-05-17');
});

it('menormalkan jenis kelamin dalam berbagai penulisan', function (): void {
    $admin = sebagaiAdmin();

    $berkas = berkasExcel(
        ImportMasterService::JUDUL_SISWA,
        [
            ['7000001', '', 'Siswa Laki', 'Laki-laki', 'Blitar', '', 2024, 'aktif'],
            ['7000002', '', 'Siswa Perempuan', 'PEREMPUAN', 'Blitar', '', 2024, 'aktif'],
        ]
    );

    $this->actingAs($admin)->postJson('/api/v1/siswa/import', ['berkas' => $berkas])->assertOk();

    expect(Siswa::where('nis', '7000001')->first()?->jenis_kelamin)->toBe('L')
        ->and(Siswa::where('nis', '7000002')->first()?->jenis_kelamin)->toBe('P');
});

it('membuat akun dan peran saat import pegawai (KP-1.4)', function (): void {
    $admin = sebagaiAdmin();

    $berkas = berkasExcel(
        ImportMasterService::JUDUL_PEGAWAI,
        [
            ['9000001', 'Guru Import', 'L', 'guru', 'Guru MTK', 'PNS', '', '', ''],
            ['9000002', 'Tendik Import', 'P', 'struktural', 'Staf TU', 'GTY', '', '', ''],
        ]
    );

    $respons = $this->actingAs($admin)->postJson('/api/v1/pegawai/import', ['berkas' => $berkas]);

    $respons->assertOk()->assertJsonPath('data.berhasil', 2);

    // Password awal setiap akun dilaporkan agar dapat diserahkan admin.
    expect($respons->json('data.akun'))->toHaveCount(2);

    $guru = User::where('username', '9000001')->first();
    $tendik = User::where('username', '9000002')->first();

    expect($guru?->kodePeran())->toBe([Role::GURU])
        ->and($tendik?->kodePeran())->toBe([Role::PEGAWAI_STRUKTURAL]);

    // Password awal yang dilaporkan dapat dipakai untuk masuk.
    $this->postJson('/api/v1/auth/login', [
        'username' => '9000001',
        'password' => $respons->json('data.akun.0.password_awal'),
    ])->assertOk();
});

it('menolak berkas dengan ekstensi yang tidak didukung', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)
        ->postJson('/api/v1/siswa/import', [
            'berkas' => UploadedFile::fake()->create('data.pdf', 10, 'application/pdf'),
        ])
        ->assertStatus(422);
});

it('menyediakan templat import yang dapat diunduh', function (): void {
    $admin = sebagaiAdmin();

    foreach (['/api/v1/siswa/template', '/api/v1/pegawai/template'] as $jalur) {
        $this->actingAs($admin)
            ->get($jalur)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
});

it('menyediakan export daftar siswa dan pegawai', function (): void {
    $admin = sebagaiAdmin();
    Siswa::factory()->count(3)->create();
    Pegawai::factory()->count(2)->create();

    foreach (['/api/v1/siswa/ekspor', '/api/v1/pegawai/ekspor', '/api/v1/mapel/ekspor'] as $jalur) {
        $this->actingAs($admin)
            ->get($jalur)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // Daftar PDF belum tersedia pada Fase 1 — harus ditolak dengan jelas, bukan 500.
    $this->actingAs($admin)->getJson('/api/v1/siswa/ekspor?format=pdf')->assertStatus(422);
});

/* ---------------------------------------------------------------------------------
 | Verifikasi berkas XLSX (risiko berkas korup adalah masalah nyata PhpSpreadsheet)
 | -------------------------------------------------------------------------------- */

it('menulis berkas XLSX yang sah dan dapat dibuka kembali', function (): void {
    $jalur = tempnam(sys_get_temp_dir(), 'sipandu_uji_').'.xlsx';

    app(ExcelService::class)->tulisKeBerkas(
        $jalur,
        ['NIS', 'Nama', 'Tanggal Lahir', 'Tahun Masuk'],
        [
            ['0012345678', 'Ahmad Fauzi', '2010-05-17', 2024],
            ['0012345679', 'Siti Aminah', '2010-06-18', 2024],
        ],
        [2],
        [0]
    );

    expect(file_exists($jalur))->toBeTrue();

    // XLSX wajib diawali magic bytes ZIP dan memuat sharedStrings (tempat teks disimpan).
    expect(file_get_contents($jalur, false, null, 0, 4))->toBe("PK\x03\x04");

    $zip = new ZipArchive;
    expect($zip->open($jalur))->toBeTrue();
    expect($zip->locateName('xl/sharedStrings.xml'))->not->toBeFalse();
    $zip->close();

    $spreadsheet = IOFactory::load($jalur);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getCell('B2')->getValue())->toBe('Ahmad Fauzi')
        ->and($sheet->getCell('A2')->getValue())->toBe('0012345678')
        ->and($sheet->getCell('C2')->getFormattedValue())->toBe('2010-05-17');

    $spreadsheet->disconnectWorksheets();
    @unlink($jalur);
});

it('tetap menghasilkan berkas yang sah walau data memuat byte UTF-8 rusak', function (): void {
    $jalur = tempnam(sys_get_temp_dir(), 'sipandu_uji_').'.xlsx';

    // Byte 0xE2 menggantung — penyebab khas "unreadable content" pada Excel.
    $rusak = "Nama \xE2 Rusak \x28\xA1";

    app(ExcelService::class)->tulisKeBerkas($jalur, ['Nama'], [[$rusak]]);

    $zip = new ZipArchive;
    expect($zip->open($jalur))->toBeTrue();
    $shared = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();

    expect($shared)->toBeString()
        ->and(mb_check_encoding($shared, 'UTF-8'))->toBeTrue();

    $spreadsheet = IOFactory::load($jalur);
    expect($spreadsheet->getActiveSheet()->getCell('A2')->getValue())->toBeString();

    $spreadsheet->disconnectWorksheets();
    @unlink($jalur);
});
