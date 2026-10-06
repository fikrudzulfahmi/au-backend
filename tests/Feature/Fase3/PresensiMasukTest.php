<?php

declare(strict_types=1);

use App\Models\LokasiPresensi;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PengajuanLuarRadius;
use App\Models\PresensiPegawai;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

beforeEach(function (): void {
    siapkanPeran();
    Storage::fake('local');
});

/** KP-3.1 — presensi tanpa foto dari kamera atau tanpa GPS ditolak. */
it('menolak presensi tanpa foto (KP-3.1)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    $respons = $this->actingAs($p['user'])->post('/api/v1/presensi/masuk', [
        'lat' => -7.8654000, 'lng' => 111.4650000, 'akurasi_m' => 10,
    ]);

    $respons->assertStatus(422);
    expect(PresensiPegawai::count())->toBe(0);
});

it('menolak presensi tanpa koordinat GPS (KP-3.1)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    $this->actingAs($p['user'])->post('/api/v1/presensi/masuk', [
        'foto' => fotoUji(), 'akurasi_m' => 10,
    ])->assertStatus(422);

    expect(PresensiPegawai::count())->toBe(0);
});

/** FR-PRS-06 — akurasi GPS buruk BUKAN "luar radius"; presensi tidak dikirim. */
it('menolak presensi saat akurasi GPS melebihi batas, bukan menganggapnya luar radius (FR-PRS-06)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    $respons = kirimPresensi($p['user'], ['akurasi_m' => 120]);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('AKURASI-GPS');
    // Galat dikaitkan ke bidang `akurasi_m`, bukan `lat` — di situlah nilai yang salah.
    expect($respons->json('errors.akurasi_m.0'))->toContain('120 m');
    expect(PresensiPegawai::count())->toBe(0);
});

/** KP-3.2 — di dalam radius → valid. */
it('menyimpan presensi valid saat berada di dalam radius (KP-3.2)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    $respons = kirimPresensi($p['user']);

    $respons->assertCreated()->assertJsonPath('data.masuk.validasi', 'valid');

    $presensi = PresensiPegawai::firstOrFail();
    expect($presensi->masuk_validasi)->toBe(PresensiPegawai::VALID)
        ->and($presensi->masuk_lokasi_id)->toBe($p['lokasi']->id)
        // Titik identik dengan pusat lokasi → jarak mendekati 0.
        ->and($presensi->masuk_jarak_m)->toBeLessThan(5)
        ->and($presensi->masuk_lokasi_id)->not->toBeNull();
});

/** KP-3.2 / BR-17 Jalur B — di luar radius tanpa pengajuan → menunggu, alasan wajib. */
it('menandai menunggu saat di luar radius tanpa pengajuan dan mewajibkan alasan (KP-3.2)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    // Tanpa alasan → ditolak dengan kode yang jelas.
    $tanpaAlasan = kirimPresensi($p['user'], ['lat' => $p['koordinat']['luar']['lat'], 'lng' => $p['koordinat']['luar']['lng']]);
    $tanpaAlasan->assertStatus(422);
    expect($tanpaAlasan->json('code'))->toBe('ALASAN-WAJIB');
    expect(PresensiPegawai::count())->toBe(0);

    // Dengan alasan → tersimpan sebagai menunggu.
    $denganAlasan = kirimPresensi($p['user'], [
        'lat' => $p['koordinat']['luar']['lat'],
        'lng' => $p['koordinat']['luar']['lng'],
        'alasan' => 'Mengantar siswa lomba ke luar kota.',
    ]);

    $denganAlasan->assertCreated()->assertJsonPath('data.masuk.validasi', 'menunggu');

    $presensi = PresensiPegawai::firstOrFail();
    expect($presensi->masuk_lokasi_id)->toBeNull()
        ->and($presensi->masuk_jarak_m)->toBeGreaterThan(1000)
        ->and($presensi->masuk_alasan_luar_radius)->toContain('lomba')
        // BR-18 — presensi menunggu belum dihitung hadir.
        ->and($presensi->dihitungHadir())->toBeFalse();
});

/** KP-3.2 / BR-17 Jalur A — pengajuan luar radius disetujui → langsung disetujui. */
it('langsung menyetujui presensi luar radius bila ada pengajuan yang disetujui (KP-3.2/BR-17)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    $pengajuan = PengajuanLuarRadius::factory()->disetujui()->create([
        'pegawai_id' => $p['pegawai']->id,
        'tanggal' => seninUji(),
        'alasan' => 'Tugas luar sekolah.',
    ]);

    $respons = kirimPresensi($p['user'], [
        'lat' => $p['koordinat']['luar']['lat'],
        'lng' => $p['koordinat']['luar']['lng'],
    ]);

    $respons->assertCreated()->assertJsonPath('data.masuk.validasi', 'disetujui');

    $presensi = PresensiPegawai::firstOrFail();
    expect($presensi->masuk_pengajuan_luar_radius_id)->toBe($pengajuan->id)
        ->and($presensi->masuk_alasan_luar_radius)->toBeNull()
        // Disetujui → sudah dihitung hadir (BR-18).
        ->and($presensi->dihitungHadir())->toBeTrue();
});

/** KP-3.3 — terlambat tanpa toleransi, menit tepat. */
it('menandai terlambat tanpa toleransi dengan menit yang tepat (KP-3.3)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:12:00'));
    $p = siapkanPresensi();

    kirimPresensi($p['user'])->assertCreated();

    $presensi = PresensiPegawai::firstOrFail();
    expect($presensi->masuk_status)->toBe(PresensiPegawai::STATUS_TERLAMBAT)
        ->and($presensi->masuk_menit_terlambat)->toBe(12);
});

it('menghitung satu detik lewat sebagai satu menit terlambat (BR-15 dibulatkan ke atas)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:01'));
    $p = siapkanPresensi();

    kirimPresensi($p['user'])->assertCreated();

    expect(PresensiPegawai::firstOrFail()->masuk_menit_terlambat)->toBe(1);
});

it('tepat pada jam masuk belum dihitung terlambat (BR-15)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    kirimPresensi($p['user'])->assertCreated();

    $presensi = PresensiPegawai::firstOrFail();
    expect($presensi->masuk_status)->toBe(PresensiPegawai::STATUS_HADIR)
        ->and($presensi->masuk_menit_terlambat)->toBe(0);
});

it('menolak presensi sebelum jam buka (FR-LOK-04)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 06:00:00'));
    $p = siapkanPresensi();

    $respons = kirimPresensi($p['user']);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BELUM-DIBUKA');
});

/** BR-10 — presensi masuk satu kali per hari. */
it('menolak presensi masuk kedua pada hari yang sama (BR-10)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    kirimPresensi($p['user'])->assertCreated();

    $kedua = kirimPresensi($p['user']);
    $kedua->assertStatus(422);
    expect($kedua->json('code'))->toBe('BR-10');
    expect(PresensiPegawai::count())->toBe(1);
});

it('menolak presensi pada hari bukan hari kerja', function (): void {
    // Sabtu, 10 Oktober 2026 — jam kerja guru ditandai bukan hari kerja.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 07:00:00'));
    $p = siapkanPresensi();

    $respons = kirimPresensi($p['user']);
    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BUKAN-HARI-KERJA');
});

/** KP-3.6 / BR-12 — pegawai tanpa penetapan khusus memakai lokasi default. */
it('memakai lokasi default bagi pegawai tanpa penetapan khusus (KP-3.6/BR-12)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    expect($p['pegawai']->lokasi()->count())->toBe(0);

    $status = $this->actingAs($p['user'])->get('/api/v1/presensi/hari-ini');

    $status->assertOk();
    expect($status->json('data.lokasi'))->toHaveCount(1)
        ->and($status->json('data.lokasi.0.id'))->toBe($p['lokasi']->id);
});

/** KP-3.6 / BR-11 — pegawai dengan beberapa lokasi valid bila berada di salah satunya. */
it('menerima presensi bila berada dalam salah satu radius milik pegawai (KP-3.6/BR-11)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    // Lokasi kedua jauh dari lokasi default, dan ditetapkan ke pegawai.
    $kedua = LokasiPresensi::factory()->create([
        'nama' => 'Lokasi Cabang',
        'latitude' => -7.9000000,
        'longitude' => 111.4750000,
        'radius_m' => 100,
        'is_default' => false,
    ]);

    $p['pegawai']->lokasi()->attach([$p['lokasi']->id, $kedua->id]);

    // Berada dekat lokasi kedua (bukan default) → tetap valid dan tercatat lokasinya.
    $respons = kirimPresensi($p['user'], [
        'lat' => -7.9000000,
        'lng' => 111.4750000,
    ]);

    $respons->assertCreated()->assertJsonPath('data.masuk.validasi', 'valid');

    $presensi = PresensiPegawai::firstOrFail();
    expect($presensi->masuk_lokasi_id)->toBe($kedua->id)
        ->and($presensi->masuk_validasi)->toBe(PresensiPegawai::VALID);
});

/**
 * KP-3.7 — foto tersimpan <= 150 KB dan memiliki watermark.
 * Pembuktian watermark: hasil layanan dibandingkan dengan berkas sumber yang
 * diperkecil & dikompres dengan parameter yang sama TANPA watermark. JPEG bersifat
 * deterministik, jadi berkas yang berbeda berarti ada piksel tambahan (watermark).
 */
it('menyimpan foto maksimal 150 KB dan berwatermark (KP-3.7/BR-29)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    $berkas = fotoUji(1600, 1200);
    $sumber = $berkas->getRealPath();

    kirimPresensi($p['user'], [], $berkas)->assertCreated();

    $presensi = PresensiPegawai::firstOrFail();
    $isi = Storage::disk('local')->get($presensi->masuk_foto_path);

    expect(strlen($isi))->toBeLessThanOrEqual(150 * 1024)
        ->and($presensi->masuk_foto_path)->toStartWith('presensi/');

    $ukur = getimagesizefromstring($isi);
    expect($ukur[0])->toBeLessThanOrEqual(800);

    // Versi tanpa watermark dari berkas sumber yang sama.
    $polos = ImageManager::gd()->read($sumber);
    $polos->scaleDown(width: 800, height: 800);
    $tanpaWatermark = (string) $polos->toJpeg(65);

    expect($isi)->not->toBe($tanpaWatermark);
});

/** KP-3.10 / BR-13 — waktu presensi memakai waktu server, bukan waktu perangkat. */
it('memakai waktu server walau klien mengirim waktu lain (KP-3.10/BR-13)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:05:00'));
    $p = siapkanPresensi();

    // Klien nakal mengirim jam perangkat yang jauh berbeda.
    kirimPresensi($p['user'], [
        'masuk_waktu' => '2020-01-01 23:59:00',
        'waktu' => '2020-01-01 23:59:00',
        'tanggal' => '2020-01-01',
    ])->assertCreated();

    $presensi = PresensiPegawai::firstOrFail();

    expect($presensi->masuk_waktu->toDateTimeString())->toBe(seninUji().' 07:05:00')
        ->and($presensi->tanggal->toDateString())->toBe(seninUji());
});

/** FR-PRS-09 / BR-25 — hari izin/sakit/cuti disetujui tidak mewajibkan presensi. */
it('menolak presensi pada hari izin yang disetujui dan menyembunyikan tombolnya (BR-25)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    PengajuanIzin::factory()->disetujui()->create([
        'pegawai_id' => $p['pegawai']->id,
        'jenis' => PengajuanIzin::JENIS_IZIN,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    $respons = kirimPresensi($p['user']);
    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BR-25');

    // Beranda menandai presensi tidak diperlukan.
    $status = $this->actingAs($p['user'])->get('/api/v1/presensi/hari-ini');
    $status->assertOk()
        ->assertJsonPath('data.boleh_masuk', false)
        ->assertJsonPath('data.pengajuan.jenis', 'izin');

    expect($status->json('data.alasan_tidak_boleh_masuk'))->toContain('BR-25');
});

/** BERHALANGAN: dinas disetujui TIDAK membebaskan presensi (BR-25). */
it('tetap mewajibkan presensi pada hari dinas yang disetujui (BR-25)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    PengajuanIzin::factory()->disetujui()->dinas()->create([
        'pegawai_id' => $p['pegawai']->id,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    kirimPresensi($p['user'])->assertCreated();

    expect(PresensiPegawai::count())->toBe(1);
});

/** Akun tanpa data pegawai ditolak dengan pesan yang jelas, bukan 403. */
it('menolak presensi untuk akun yang tidak terhubung ke pegawai', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    siapkanPresensi();

    $admin = sebagaiAdmin();

    $respons = kirimPresensi($admin);
    $respons->assertStatus(422);
    expect($respons->json('errors.pegawai.0'))->toContain('tidak terhubung ke data pegawai');
});
