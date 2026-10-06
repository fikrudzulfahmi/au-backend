<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Jurnal;
use App\Models\JurnalFoto;
use App\Models\Pegawai;
use App\Models\PresensiPegawai;
use App\Models\Semester;
use App\Models\TahunPelajaran;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Storage;

/**
 * BR-30 + A-07 + A-12 — retensi foto.
 *
 * Berkas foto presensi dan lampiran foto jurnal disimpan selama satu tahun
 * pelajaran. Setelah tahun pelajaran berstatus `selesai`, berkas fisiknya
 * dibuang, kolom path diisi NULL, dan `foto_dihapus_pada` ditandai, sementara
 * data teks presensi tetap utuh. Perlindungan terpenting: tahun pelajaran yang
 * masih AKTIF tidak pernah tersentuh.
 */
beforeEach(function (): void {
    siapkanPeran();
    Storage::fake('local');
});

/**
 * Tahun pelajaran beserta semester ganjil miliknya.
 *
 * @return array{0: TahunPelajaran, 1: Semester}
 */
function tpDenganSemester(string $status, string $nama): array
{
    /** @var TahunPelajaran $tahun */
    $tahun = TahunPelajaran::factory()->create([
        'nama' => $nama,
        'status' => $status,
    ]);

    $semester = $tahun->semester()->where('jenis', 'ganjil')->first()
        ?? $tahun->semester()->create([
            'jenis' => 'ganjil',
            'tanggal_mulai' => $tahun->tanggal_mulai,
            'is_active' => $status === TahunPelajaran::STATUS_AKTIF,
        ]);

    return [$tahun->fresh('semester'), $semester];
}

/** Presensi pegawai dengan dua berkas foto sungguhan pada disk `local`. */
function presensiBerfoto(Semester $semester, array $ganti = []): PresensiPegawai
{
    $masuk = 'presensi/uji/masuk-'.uniqid().'.jpg';
    $pulang = 'presensi/uji/pulang-'.uniqid().'.jpg';

    Storage::disk('local')->put($masuk, 'isi-foto-masuk');
    Storage::disk('local')->put($pulang, 'isi-foto-pulang');

    return PresensiPegawai::factory()->create(array_merge([
        'pegawai_id' => Pegawai::factory(),
        'semester_id' => $semester->id,
        'masuk_foto_path' => $masuk,
        'pulang_foto_path' => $pulang,
        'masuk_lat' => -7.8654123,
        'masuk_lng' => 111.4650987,
        'pulang_waktu' => '2027-06-10 15:00:00',
        'pulang_status' => PresensiPegawai::PULANG_NORMAL,
        'pulang_validasi' => PresensiPegawai::VALID,
    ], $ganti));
}

it('BR-30 menghapus berkas foto tahun pelajaran selesai lalu mengisi path NULL dan tanda hapus', function (): void {
    [, $semester] = tpDenganSemester(TahunPelajaran::STATUS_SELESAI, '2025/2026');
    $presensi = presensiBerfoto($semester);

    $masuk = $presensi->masuk_foto_path;
    $pulang = $presensi->pulang_foto_path;

    $this->artisan('presensi:bersihkan-foto')->assertExitCode(0);

    Storage::disk('local')->assertMissing($masuk);
    Storage::disk('local')->assertMissing($pulang);

    $baru = $presensi->fresh();

    expect($baru->masuk_foto_path)->toBeNull()
        ->and($baru->pulang_foto_path)->toBeNull()
        ->and($baru->foto_dihapus_pada)->not->toBeNull()
        ->and(PresensiPegawai::query()->whereKey($presensi->getKey())->exists())->toBeTrue();

    // Jejak audit tercatat (BR-31).
    expect(AuditLog::query()->where('aksi', AuditLogService::AKSI_BERSIHKAN_FOTO)->count())->toBe(1);
});

it('BR-30 data teks presensi tetap utuh setelah pembersihan', function (): void {
    [, $semester] = tpDenganSemester(TahunPelajaran::STATUS_SELESAI, '2025/2026');
    $presensi = presensiBerfoto($semester);

    $kolom = ['pegawai_id', 'tanggal', 'masuk_waktu', 'masuk_lat', 'masuk_lng', 'masuk_status',
        'masuk_validasi', 'pulang_waktu', 'pulang_status', 'pulang_validasi'];
    $sebelum = $presensi->only($kolom);

    $this->artisan('presensi:bersihkan-foto')->assertExitCode(0);

    expect($presensi->fresh()->only($kolom))->toEqual($sebelum);
});

it('BR-30 tahun pelajaran AKTIF tidak pernah tersentuh, bahkan bila diminta langsung', function (): void {
    [$tahun, $semester] = tpDenganSemester(TahunPelajaran::STATUS_AKTIF, '2026/2027');
    $presensi = presensiBerfoto($semester);

    $masuk = $presensi->masuk_foto_path;
    $pulang = $presensi->pulang_foto_path;

    // (a) jalan tanpa opsi: hanya tahun `selesai` yang diproses
    $this->artisan('presensi:bersihkan-foto')->assertExitCode(0);
    // (b) diminta lewat nama
    $this->artisan('presensi:bersihkan-foto', ['--tahun-pelajaran' => '2026/2027'])->assertExitCode(0);
    // (c) diminta lewat id
    $this->artisan('presensi:bersihkan-foto', ['--tahun-pelajaran' => (string) $tahun->id])->assertExitCode(0);

    Storage::disk('local')->assertExists($masuk);
    Storage::disk('local')->assertExists($pulang);

    $baru = $presensi->fresh();

    expect($baru->masuk_foto_path)->toBe($masuk)
        ->and($baru->pulang_foto_path)->toBe($pulang)
        ->and($baru->foto_dihapus_pada)->toBeNull()
        ->and(AuditLog::query()->where('aksi', AuditLogService::AKSI_BERSIHKAN_FOTO)->count())->toBe(0);
});

it('BR-30 mode kering tidak menghapus berkas maupun mengubah kolom', function (): void {
    [, $semester] = tpDenganSemester(TahunPelajaran::STATUS_SELESAI, '2025/2026');
    $presensi = presensiBerfoto($semester);

    $masuk = $presensi->masuk_foto_path;
    $pulang = $presensi->pulang_foto_path;

    $this->artisan('presensi:bersihkan-foto', ['--kering' => true])->assertExitCode(0);
    $this->artisan('presensi:bersihkan-foto', ['--dry-run' => true])->assertExitCode(0);

    Storage::disk('local')->assertExists($masuk);
    Storage::disk('local')->assertExists($pulang);

    $baru = $presensi->fresh();

    expect($baru->masuk_foto_path)->toBe($masuk)
        ->and($baru->pulang_foto_path)->toBe($pulang)
        ->and($baru->foto_dihapus_pada)->toBeNull()
        ->and(AuditLog::query()->where('aksi', AuditLogService::AKSI_BERSIHKAN_FOTO)->count())->toBe(0);
});

it('BR-30 perintah idempoten — dijalankan dua kali tetap lulus dan tidak menghapus dua kali', function (): void {
    [, $semester] = tpDenganSemester(TahunPelajaran::STATUS_SELESAI, '2025/2026');
    $presensi = presensiBerfoto($semester);

    $this->artisan('presensi:bersihkan-foto')->assertExitCode(0);
    $tandaPertama = $presensi->fresh()->foto_dihapus_pada;

    expect($tandaPertama)->not->toBeNull();

    $this->artisan('presensi:bersihkan-foto')->assertExitCode(0);

    expect($presensi->fresh()->foto_dihapus_pada->equalTo($tandaPertama))->toBeTrue()
        ->and($presensi->fresh()->masuk_foto_path)->toBeNull();
});

it('BR-30 berkas yang sudah tiada dilaporkan, bukan dianggap gagal', function (): void {
    [, $semester] = tpDenganSemester(TahunPelajaran::STATUS_SELESAI, '2025/2026');

    // Path menunjuk berkas yang tidak pernah ada di disk.
    $presensi = presensiBerfoto($semester, [
        'masuk_foto_path' => 'presensi/uji/hilang-masuk.jpg',
        'pulang_foto_path' => null,
    ]);

    $this->artisan('presensi:bersihkan-foto')->assertExitCode(0);

    $baru = $presensi->fresh();

    expect($baru->masuk_foto_path)->toBeNull()
        ->and($baru->foto_dihapus_pada)->not->toBeNull();
});

it('A-12 berkas lampiran jurnal tahun selesai ikut dibersihkan tanpa menghapus jurnalnya', function (): void {
    // Nama tahun dibedakan dari default factory karena `Jurnal::factory()` juga
    // membuat tahun pelajaran bawaannya sendiri (nama unik di level database).
    [, $semester] = tpDenganSemester(TahunPelajaran::STATUS_SELESAI, '2018/2019');

    /** @var Jurnal $jurnal */
    $jurnal = Jurnal::factory()->create(['semester_id' => $semester->id]);

    $path = 'jurnal/uji/kegiatan-'.uniqid().'.jpg';
    Storage::disk('local')->put($path, 'isi-foto-jurnal');

    /** @var JurnalFoto $foto */
    $foto = JurnalFoto::factory()->create(['jurnal_id' => $jurnal->id, 'foto_path' => $path, 'urutan' => 1]);

    $this->artisan('presensi:bersihkan-foto')->assertExitCode(0);

    Storage::disk('local')->assertMissing($path);

    $fotoBaru = $foto->fresh();

    expect($fotoBaru->foto_path)->toBeNull()
        ->and($fotoBaru->foto_dihapus_pada)->not->toBeNull()
        // Baris jurnal & presensi siswa tetap ada (hanya berkas yang dibuang).
        ->and(Jurnal::query()->whereKey($jurnal->getKey())->exists())->toBeTrue();
});

it('BR-30 endpoint pembersihan manual hanya untuk admin, butuh konfirmasi, dan menolak tahun aktif', function (): void {
    [$tahun, $semester] = tpDenganSemester(TahunPelajaran::STATUS_SELESAI, '2025/2026');
    $presensi = presensiBerfoto($semester);

    $url = "/api/v1/tahun-pelajaran/{$tahun->id}/bersihkan-foto";

    // Guru bukan admin → 403.
    $this->actingAs(buatPegawaiDenganAkun())
        ->postJson($url, ['konfirmasi' => true])
        ->assertForbidden();

    // Admin tanpa konfirmasi → 422 (BR-31).
    $admin = sebagaiAdmin();
    $this->actingAs($admin)->postJson($url, [])->assertStatus(422);

    // Tahun pelajaran aktif ditolak walau admin & sudah konfirmasi.
    [$aktif] = tpDenganSemester(TahunPelajaran::STATUS_AKTIF, '2026/2027');
    $this->actingAs($admin)
        ->postJson("/api/v1/tahun-pelajaran/{$aktif->id}/bersihkan-foto", ['konfirmasi' => true])
        ->assertStatus(422);

    // Admin + konfirmasi pada tahun selesai → 200 dan berkas benar-benar hilang.
    $masuk = $presensi->masuk_foto_path;

    $this->actingAs($admin)
        ->postJson($url, ['konfirmasi' => true])
        ->assertOk()
        ->assertJsonPath('data.berkas_terhapus', 2);

    Storage::disk('local')->assertMissing($masuk);
    expect($presensi->fresh()->masuk_foto_path)->toBeNull();
});

it('BR-30 endpoint foto menjawab 404 berpesan untuk berkas yang sudah dibersihkan', function (): void {
    [, $semester] = tpDenganSemester(TahunPelajaran::STATUS_SELESAI, '2025/2026');
    $presensi = presensiBerfoto($semester);

    $this->artisan('presensi:bersihkan-foto')->assertExitCode(0);

    // Penyajian foto presensi yang path-nya sudah NULL → 404 dengan pesan jelas.
    $this->actingAs(sebagaiAdmin())
        ->getJson("/api/v1/presensi/{$presensi->id}/foto/masuk")
        ->assertStatus(404)
        ->assertJsonPath('message', 'Foto tidak tersedia (mungkin sudah dihapus sesuai masa retensi).');
});
