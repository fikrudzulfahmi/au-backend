<?php

declare(strict_types=1);

use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\Pengumuman;
use App\Models\PresensiPegawai;
use App\Services\Laporan\LaporanJurnalService;
use App\Services\Laporan\LaporanPresensiService;
use App\Services\PengaturanService;
use App\Services\TvRekapService;
use App\Services\TvSesiService;
use App\Support\PeriodeLaporan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Fase 6 — /tv/rekap: BR-37, BR-33, KP-6.6
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    siapkanPeran();
    Cache::flush();
});

function tvRekapToken(): string
{
    return (string) test()->postJson('/api/v1/tv/masuk', [
        'kode' => app(TvSesiService::class)->kode(),
        'nama_perangkat' => 'TV Uji',
    ])->assertOk()->json('data.token');
}

/** Mengumpulkan seluruh nama kunci pada struktur JSON apa pun. */
function tvKunciRekursif(array $data): array
{
    $kunci = [];

    foreach ($data as $k => $v) {
        $kunci[] = $k;

        if (is_array($v)) {
            $kunci = array_merge($kunci, tvKunciRekursif($v));
        }
    }

    return $kunci;
}

it('BR-37 angka TV sama dengan laporan Fase 5 untuk tanggal yang sama', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $rangkaian = siapkanJurnal();
    pasangJamKerja();
    setujuiIzin($rangkaian['guru'], seninUji(), null, PengajuanIzin::JENIS_SAKIT)
        ->update(['alasan' => 'Demam tinggi']);

    $token = tvRekapToken();
    $tv = test()->withToken($token)
        ->getJson('/api/v1/tv/rekap?tanggal='.seninUji())
        ->assertOk()
        ->json('data');

    // Kolom presensi.
    $laporanPresensi = app(LaporanPresensiService::class)->harian(seninUji());
    expect($tv['presensi']['ringkasan'])->toEqual($laporanPresensi['ringkasan']);

    // Kolom jurnal.
    $laporanJurnal = app(LaporanJurnalService::class)
        ->kepatuhanJurnal($rangkaian['semester'], PeriodeLaporan::buat(['periode' => 'hari_ini']));
    $nilai = collect($laporanJurnal['ringkasan'])->pluck('nilai', 'label');

    expect($tv['jurnal']['terjadwal'])->toBe((int) $nilai->get('Terjadwal'))
        ->and($tv['jurnal']['terisi'])->toBe((int) $nilai->get('Terisi'))
        ->and($tv['jurnal']['belum_terisi'])->toBe((int) $nilai->get('Belum'))
        ->and($tv['jurnal']['berhalangan'])->toBe((int) $nilai->get('Berhalangan'));

    // Kolom perizinan.
    $laporanIzin = app(LaporanPresensiService::class)
        ->rekapIzin(PeriodeLaporan::buat(['periode' => 'hari_ini']));
    expect($tv['perizinan']['ringkasan'])->toEqual($laporanIzin['ringkasan']);
});

it('BR-37 kolom presensi memuat daftar pegawai tanpa NIP', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $ring = siapkanPresensi();
    PresensiPegawai::factory()->create([
        'pegawai_id' => $ring['pegawai']->id,
        'tanggal' => seninUji(),
        'semester_id' => semesterAktif()->id,
        'masuk_validasi' => PresensiPegawai::VALID,
    ]);

    $token = tvRekapToken();
    $tv = test()->withToken($token)->getJson('/api/v1/tv/rekap?tanggal='.seninUji())->json('data');

    expect($tv['presensi']['sudah_presensi'])->not->toBeEmpty();
    expect($tv['presensi']['sudah_presensi'][0])->toHaveKeys(['inisial', 'nama', 'jam_masuk', 'status']);
});

it('BR-33 respons TV tidak memuat data pribadi atau berkas privat', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $ring = siapkanPresensi();
    PresensiPegawai::factory()->create([
        'pegawai_id' => $ring['pegawai']->id,
        'tanggal' => seninUji(),
        'semester_id' => semesterAktif()->id,
        'masuk_validasi' => PresensiPegawai::VALID,
    ]);
    setujuiIzin($ring['pegawai'], seninUji(), null, PengajuanIzin::JENIS_SAKIT)->update(['alasan' => 'Demam']);

    $token = tvRekapToken();
    $isi = test()->withToken($token)->getJson('/api/v1/tv/rekap?tanggal='.seninUji())->assertOk()->json('data');

    $kunci = tvKunciRekursif($isi);

    foreach (['nip', 'no_hp', 'foto_path', 'masuk_foto_path', 'pulang_foto_path', 'lat', 'lng', 'latitude', 'longitude', 'koordinat', 'jarak_m', 'ada_foto', 'alasan_luar_radius'] as $terlarang) {
        expect($kunci)->not->toContain($terlarang);
    }

    // Alasan sakit tidak tampil selama opsi dinonaktifkan (default).
    expect($kunci)->not->toContain('alasan');

    // Avatar pegawai berupa huruf inisial.
    $inisial = $isi['presensi']['sudah_presensi'][0]['inisial'] ?? $isi['perizinan']['daftar'][0]['inisial'] ?? null;
    expect($inisial)->toBeString()->not->toBeEmpty();
});

it('BR-33 alasan izin tampil hanya bila opsi diaktifkan', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $ring = siapkanPresensi();
    setujuiIzin($ring['pegawai'], seninUji(), null, PengajuanIzin::JENIS_SAKIT)->update(['alasan' => 'Demam tinggi']);

    app(PengaturanService::class)->simpan('tv_tampilkan_alasan_izin', true);
    Cache::flush();

    $token = tvRekapToken();
    $tv = test()->withToken($token)->getJson('/api/v1/tv/rekap?tanggal='.seninUji())->json('data');

    expect($tv['perizinan']['tampilkan_alasan'])->toBeTrue()
        ->and($tv['perizinan']['daftar'][0]['alasan'])->toBe('Demam tinggi');
});

it('KP-6.6 banyak token pada interval sama hanya memicu satu perhitungan per tanggal', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    siapkanPresensi();

    $tokenA = tvRekapToken();
    $tokenB = tvRekapToken();

    $pertama = test()->withToken($tokenA)->getJson('/api/v1/tv/rekap?tanggal='.seninUji())->json('data');

    // Data berubah SETELAH perhitungan pertama…
    Pegawai::factory()->create(['is_active' => true]);

    // …namun permintaan dari token lain masih memakai hasil cache (tidak dihitung ulang).
    $kedua = test()->withToken($tokenB)->getJson('/api/v1/tv/rekap?tanggal='.seninUji())->json('data');

    expect($kedua)->toEqual($pertama)
        ->and(Cache::has(TvRekapService::kunciCache(seninUji())))->toBeTrue()
        ->and(TvRekapService::DETIK_CACHE)->toBe(15);

    // Cache bersifat PER TANGGAL: tanggal lain dihitung sendiri.
    $lain = test()->withToken($tokenB)->getJson('/api/v1/tv/rekap?tanggal=2026-10-06')->json('data');
    expect($lain['presensi']['tanggal'])->toBe('2026-10-06')->and($lain)->not->toEqual($pertama);
});

it('KP-6.3 memuat tiga kolom, jam server, dan pengumuman tayang', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    siapkanPresensi();

    Pengumuman::query()->create([
        'judul' => 'Mohon isi jurnal',
        'isi' => 'Setiap selesai mengajar.',
        'tipe' => Pengumuman::TIPE_PENGUMUMAN,
        'prioritas' => Pengumuman::PRIORITAS_PENTING,
        'tanggal_mulai' => '2026-10-01',
        'tanggal_selesai' => '2026-10-31',
        'tampil_tv' => true,
        'tampil_app' => false,
    ]);
    Pengumuman::query()->create([
        'judul' => 'Selamat datang',
        'isi' => 'Teks berjalan uji',
        'tipe' => Pengumuman::TIPE_TEKS_BERJALAN,
        'tanggal_mulai' => '2026-10-01',
        'tampil_tv' => true,
        'tampil_app' => false,
    ]);

    $token = tvRekapToken();
    $tv = test()->withToken($token)->getJson('/api/v1/tv/rekap')->assertOk()->json('data');

    expect($tv)->toHaveKeys(['server', 'presensi', 'jurnal', 'perizinan', 'pengumuman', 'pengaturan'])
        ->and($tv['server'])->toHaveKeys(['waktu', 'tanggal', 'nama_hari', 'zona'])
        ->and($tv['server']['zona'])->toBe('Asia/Jakarta')
        ->and($tv['pengumuman']['kartu'])->toHaveCount(1)
        ->and($tv['pengumuman']['kartu'][0]['penting'])->toBeTrue()
        ->and($tv['pengumuman']['teks_berjalan'])->toBe(['Teks berjalan uji']);
});
