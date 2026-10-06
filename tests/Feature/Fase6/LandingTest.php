<?php

declare(strict_types=1);

use App\Models\Pengumuman;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Fase 6 — Landing page (5.21, FR-LND-05/09/10, BR-34)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    siapkanPeran();
    Cache::flush();
});

function lndBuat(array $atribut = []): Pengumuman
{
    return Pengumuman::query()->create(array_merge([
        'judul' => 'Pengumuman Landing',
        'isi' => 'Selamat datang di SIPANDU.',
        'tipe' => Pengumuman::TIPE_PENGUMUMAN,
        'prioritas' => Pengumuman::PRIORITAS_NORMAL,
        'tanggal_mulai' => '2026-10-01',
        'tanggal_selesai' => '2026-10-31',
        'tampil_app' => false,
        'tampil_tv' => false,
        'tampil_landing' => true,
        'is_active' => true,
    ], $atribut));
}

it('BR-34 landing hanya memuat pengumuman bertanda tampil_landing yang tayang', function (): void {
    lndBuat(['judul' => 'Tampil di landing']);
    lndBuat(['judul' => 'Tidak ditandai', 'tampil_landing' => false]);
    lndBuat(['judul' => 'Kedaluwarsa', 'tanggal_mulai' => '2026-09-01', 'tanggal_selesai' => '2026-09-05']);
    lndBuat(['judul' => 'Teks berjalan', 'tipe' => Pengumuman::TIPE_TEKS_BERJALAN]);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00'));

    profilSekolah();

    test()->getJson('/api/v1/publik/pengumuman')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.judul', 'Tampil di landing');
});

it('BR-34 respons landing tidak memuat data pegawai, siswa, atau kehadiran', function (): void {
    lndBuat();
    profilSekolah();

    $isi = test()->getJson('/api/v1/publik/pengumuman')->assertOk()->json();
    $kunci = tvKunciRekursifLnd($isi);

    foreach (['pegawai', 'siswa', 'hadir', 'kehadiran', 'nip', 'no_hp', 'presensi', 'jurnal'] as $terlarang) {
        expect($kunci)->not->toContain($terlarang);
    }
});

it('BR-34 info sekolah publik tidak memuat angka kehadiran', function (): void {
    profilSekolah(['nama_sekolah' => 'SMK Uji Landing']);

    $isi = test()->getJson('/api/v1/publik/sekolah')->assertOk()->json();
    $kunci = tvKunciRekursifLnd($isi);

    foreach (['pegawai', 'siswa', 'hadir', 'kehadiran', 'presensi', 'jumlah_pegawai', 'jumlah_siswa'] as $terlarang) {
        expect($kunci)->not->toContain($terlarang);
    }

    expect($isi['data']['nama_sekolah'])->toBe('SMK Uji Landing');
});

it('FR-LND-09 landing tetap 200 walau belum ada pengumuman', function (): void {
    profilSekolah();

    test()->getJson('/api/v1/publik/pengumuman')->assertOk()->assertJsonCount(0, 'data');
});

it('pengumuman landing dapat dikelola admin dan disembunyikan tanpa dihapus', function (): void {
    $admin = sebagaiAdmin();
    profilSekolah();

    $id = lndBuat(['is_active' => true])->id;

    test()->actingAs($admin)->putJson('/api/v1/pengumuman/'.$id, [
        'judul' => 'Pengumuman Landing',
        'tipe' => Pengumuman::TIPE_PENGUMUMAN,
        'prioritas' => Pengumuman::PRIORITAS_NORMAL,
        'tanggal_mulai' => '2026-10-01',
        'tampil_landing' => true,
        'is_active' => false,
    ])->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00'));

    test()->getJson('/api/v1/publik/pengumuman')->assertOk()->assertJsonCount(0, 'data');
    expect(Pengumuman::query()->find($id))->not->toBeNull();
});

/** Mengumpulkan seluruh nama kunci pada struktur JSON apa pun. */
function tvKunciRekursifLnd(array $data): array
{
    $kunci = [];

    foreach ($data as $k => $v) {
        $kunci[] = $k;

        if (is_array($v)) {
            $kunci = array_merge($kunci, tvKunciRekursifLnd($v));
        }
    }

    return $kunci;
}
