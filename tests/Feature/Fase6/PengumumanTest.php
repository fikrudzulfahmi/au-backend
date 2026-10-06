<?php

declare(strict_types=1);

use App\Models\Pengumuman;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Fase 6 — Pengumuman (5.20, BR-36)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    siapkanPeran();
    Cache::flush();
});

function pmnBuat(array $atribut = []): Pengumuman
{
    return Pengumuman::query()->create(array_merge([
        'judul' => 'Rapat Dewan Guru',
        'isi' => 'Rapat Jumat 13.00 di aula.',
        'tipe' => Pengumuman::TIPE_PENGUMUMAN,
        'prioritas' => Pengumuman::PRIORITAS_NORMAL,
        'tanggal_mulai' => '2026-10-05',
        'tanggal_selesai' => '2026-10-10',
        'tampil_app' => true,
        'tampil_tv' => true,
        'tampil_landing' => false,
        'is_active' => true,
    ], $atribut));
}

it('BR-36 menayangkan pengumuman aktif yang berada dalam rentang tanggal', function (): void {
    pmnBuat(['judul' => 'Dalam rentang']);

    $dalam = Pengumuman::query()->tayang(CarbonImmutable::parse('2026-10-07 09:00:00'))->get();
    $sebelum = Pengumuman::query()->tayang(CarbonImmutable::parse('2026-10-04 09:00:00'))->get();
    $sesudah = Pengumuman::query()->tayang(CarbonImmutable::parse('2026-10-11 09:00:00'))->get();

    expect($dalam)->toHaveCount(1)
        ->and($sebelum)->toHaveCount(0)
        ->and($sesudah)->toHaveCount(0);
});

it('BR-36 tidak menayangkan pengumuman nonaktif', function (): void {
    pmnBuat(['is_active' => false]);

    expect(Pengumuman::query()->tayang(CarbonImmutable::parse('2026-10-07 09:00:00'))->count())->toBe(0);
});

it('BR-36 pengumuman kedaluwarsa tidak tampil tetapi TIDAK dihapus', function (): void {
    $lama = pmnBuat([
        'judul' => 'Sudah lewat',
        'tanggal_mulai' => '2026-09-01',
        'tanggal_selesai' => '2026-09-05',
    ]);

    expect(Pengumuman::query()->tayang(CarbonImmutable::parse('2026-10-07 09:00:00'))->count())->toBe(0);
    expect(Pengumuman::query()->find($lama->id))->not->toBeNull();
});

it('BR-36 menghormati jam tayang bila diisi', function (): void {
    pmnBuat([
        'judul' => 'Rapat siang',
        'tanggal_mulai' => '2026-10-05',
        'tanggal_selesai' => '2026-10-05',
        'jam_mulai' => '13:00',
        'jam_selesai' => '15:00',
    ]);

    $pagi = Pengumuman::query()->tayang(CarbonImmutable::parse('2026-10-05 09:00:00'))->count();
    $siang = Pengumuman::query()->tayang(CarbonImmutable::parse('2026-10-05 14:00:00'))->count();
    $malam = Pengumuman::query()->tayang(CarbonImmutable::parse('2026-10-05 20:00:00'))->count();

    expect($pagi)->toBe(0)->and($siang)->toBe(1)->and($malam)->toBe(0);
});

it('BR-36 menghormati target tampil', function (): void {
    pmnBuat(['judul' => 'Hanya TV', 'tampil_tv' => true, 'tampil_app' => false, 'tampil_landing' => false]);
    pmnBuat(['judul' => 'Hanya App', 'tampil_tv' => false, 'tampil_app' => true, 'tampil_landing' => false]);

    $saat = CarbonImmutable::parse('2026-10-07 09:00:00');

    expect(Pengumuman::query()->tayang($saat)->target(Pengumuman::TARGET_TV)->count())->toBe(1)
        ->and(Pengumuman::query()->tayang($saat)->target(Pengumuman::TARGET_APP)->count())->toBe(1)
        ->and(Pengumuman::query()->tayang($saat)->target(Pengumuman::TARGET_LANDING)->count())->toBe(0);
});

it('admin dapat membuat, mengubah, dan menghapus pengumuman', function (): void {
    $admin = sebagaiAdmin();

    $id = test()->actingAs($admin)->postJson('/api/v1/pengumuman', [
        'judul' => 'Pembagian Rapor',
        'isi' => 'Rapor dibagikan Sabtu pagi.',
        'tipe' => Pengumuman::TIPE_PENGUMUMAN,
        'prioritas' => Pengumuman::PRIORITAS_PENTING,
        'tanggal_mulai' => '2026-10-05',
        'tanggal_selesai' => '2026-10-20',
        'tampil_app' => true,
        'tampil_tv' => true,
        'tampil_landing' => true,
        'is_active' => true,
    ])->assertCreated()->json('data.id');

    test()->actingAs($admin)->putJson('/api/v1/pengumuman/'.$id, [
        'judul' => 'Pembagian Rapor (revisi)',
        'tipe' => Pengumuman::TIPE_PENGUMUMAN,
        'prioritas' => Pengumuman::PRIORITAS_NORMAL,
        'tanggal_mulai' => '2026-10-05',
    ])->assertOk()->assertJsonPath('data.judul', 'Pembagian Rapor (revisi)');

    test()->actingAs($admin)->getJson('/api/v1/pengumuman')->assertOk()->assertJsonPath('meta.total', 1);

    test()->actingAs($admin)->deleteJson('/api/v1/pengumuman/'.$id)->assertOk();
    expect(Pengumuman::query()->count())->toBe(0);
});

it('hanya admin dan kepala sekolah yang boleh mengelola pengumuman', function (): void {
    siapkanAkademik();

    $kepsek = buatPengguna([Role::KEPALA_SEKOLAH]);
    $guru = buatPegawaiDenganAkun();

    test()->actingAs($kepsek)->postJson('/api/v1/pengumuman', [
        'judul' => 'Dari kepsek',
        'tipe' => Pengumuman::TIPE_PENGUMUMAN,
        'prioritas' => Pengumuman::PRIORITAS_NORMAL,
        'tanggal_mulai' => '2026-10-05',
    ])->assertCreated();

    test()->actingAs($guru)->postJson('/api/v1/pengumuman', [
        'judul' => 'Dari guru',
        'tipe' => Pengumuman::TIPE_PENGUMUMAN,
        'prioritas' => Pengumuman::PRIORITAS_NORMAL,
        'tanggal_mulai' => '2026-10-05',
    ])->assertStatus(403);
});

it('semua pengguna login melihat pengumuman aktif pada beranda', function (): void {
    pmnBuat(['judul' => 'Tampil di beranda', 'tampil_app' => true]);
    pmnBuat(['judul' => 'Kedaluwarsa', 'tanggal_mulai' => '2026-09-01', 'tanggal_selesai' => '2026-09-02']);
    pmnBuat(['judul' => 'Hanya TV', 'tampil_app' => false]);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00'));

    $guru = buatPegawaiDenganAkun();

    test()->actingAs($guru)->getJson('/api/v1/pengumuman/aktif')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.judul', 'Tampil di beranda');
});
