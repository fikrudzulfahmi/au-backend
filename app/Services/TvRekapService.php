<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\JamKerja;
use App\Models\Pegawai;
use App\Models\PresensiSiswa;
use App\Models\Semester;
use App\Services\Laporan\LaporanJurnalService;
use App\Services\Laporan\LaporanPresensiService;
use App\Support\PeriodeLaporan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * 5.19 / FR-TV-11 — satu respons agregat untuk seluruh kolom layar TV.
 *
 * Dua aturan yang mengikat kelas ini:
 *  - BR-37: SELURUH angka diambil dari layanan laporan Fase 5
 *    (`LaporanPresensiService`, `LaporanJurnalService`) — tidak pernah dihitung
 *    ulang dari tabel mentah, supaya TV dan laporan tidak pernah berbeda.
 *  - BR-33: respons tidak memuat foto selfie, koordinat, NIP, nomor HP, alasan
 *    sakit (kecuali opsi alasan aktif), atau data siswa individual.
 *
 * KP-6.6: hasil DI-CACHE per TANGGAL selama 15 detik sehingga banyak perangkat
 * TV yang polling pada interval yang sama hanya memicu satu perhitungan.
 */
class TvRekapService
{
    /** Masa simpan cache (detik) menurut FR-TV-11 / KP-6.6. */
    public const DETIK_CACHE = 15;

    public function __construct(
        private readonly LaporanPresensiService $presensi,
        private readonly LaporanJurnalService $jurnal,
        private readonly PengaturanService $pengaturan,
        private readonly PengumumanService $pengumuman,
    ) {}

    /**
     * KP-6.6 — cache PER TANGGAL, bukan per token: seluruh perangkat TV pada
     * tanggal yang sama berbagi satu perhitungan.
     *
     * @return array<string, mixed>
     */
    public function rekap(?string $tanggal = null): array
    {
        $tanggal = $tanggal !== null && $tanggal !== ''
            ? $tanggal
            : CarbonImmutable::now()->toDateString();

        $kunci = 'tv:rekap:'.$tanggal;

        if (Cache::has($kunci)) {
            $tersimpan = Cache::get($kunci);

            if (is_array($tersimpan)) {
                return $tersimpan;
            }
        }

        $hasil = $this->bangun($tanggal);
        Cache::put($kunci, $hasil, self::DETIK_CACHE);

        return $hasil;
    }

    /** Kunci cache untuk sebuah tanggal (dipakai uji KP-6.6). */
    public static function kunciCache(string $tanggal): string
    {
        return 'tv:rekap:'.$tanggal;
    }

    /** @return array<string, mixed> */
    private function bangun(string $tanggal): array
    {
        $saat = CarbonImmutable::parse($tanggal);

        return [
            'server' => $this->server($saat),
            'presensi' => $this->kolomPresensi($tanggal),
            'jurnal' => $this->kolomJurnal($saat),
            'perizinan' => $this->kolomPerizinan(),
            'pengumuman' => $this->pengumuman->untukTv($saat),
            'ulang_tahun' => $this->ulangTahun($saat),
            'pengaturan' => $this->tampilan(),
        ];
    }

    /** FR-TV-05 — header jam server otoritatif. */
    private function server(CarbonImmutable $saat): array
    {
        return [
            'waktu' => CarbonImmutable::now()->format('H:i:s'),
            'tanggal' => $saat->toDateString(),
            'nama_hari' => JamKerja::DAFTAR_HARI[$saat->dayOfWeekIso] ?? null,
            'zona' => 'Asia/Jakarta',
        ];
    }

    /** FR-TV-06 — kolom presensi; angka diambil apa adanya dari laporan (BR-37). */
    private function kolomPresensi(string $tanggal): array
    {
        $hasil = $this->presensi->harian($tanggal);
        $detail = $hasil['detail'] ?? [];

        $sudah = collect($detail)
            ->filter(fn (array $b): bool => $b['masuk_jam'] !== null)
            ->sortByDesc('masuk_jam')
            ->values()
            ->map(fn (array $b): array => [
                'inisial' => self::inisial((string) $b['nama']),
                'nama' => $b['nama'],
                'jam_masuk' => $b['masuk_jam'],
                'status' => $b['status'],
                'label_status' => $b['label_status'],
            ])
            ->all();

        $belum = collect($detail)
            ->filter(fn (array $b): bool => ($b['masuk_jam'] ?? null) === null
                && ($b['pengajuan_jenis'] ?? null) === null)
            ->values()
            ->map(fn (array $b): array => [
                'inisial' => self::inisial((string) $b['nama']),
                'nama' => $b['nama'],
                'jenis_pegawai' => $b['jenis_pegawai'],
            ])
            ->all();

        return [
            'tanggal' => $hasil['tanggal'],
            'nama_hari' => $hasil['nama_hari'],
            'hari_libur' => $hasil['hari_libur'],
            // Angka identik dengan laporan presensi harian (BR-37).
            'ringkasan' => $hasil['ringkasan'],
            'sudah_presensi' => $sudah,
            'belum_presensi' => $belum,
        ];
    }

    /** FR-TV-07 — kolom jurnal; angka dari laporan kepatuhan jurnal (BR-37). */
    private function kolomJurnal(CarbonImmutable $saat): array
    {
        $presensiSiswa = $this->presensiSiswa($saat);

        $semester = Semester::query()->aktif()->first();

        if ($semester === null) {
            return [
                'tersedia' => false,
                'terjadwal' => 0,
                'terisi' => 0,
                'belum_terisi' => 0,
                'berhalangan' => 0,
                'persen' => null,
                'guru_belum' => [],
                'presensi_siswa' => $presensiSiswa,
            ];
        }

        $hasil = $this->jurnal->kepatuhanJurnal($semester, PeriodeLaporan::buat(['periode' => 'hari_ini']));

        $nilai = collect($hasil['ringkasan'])->pluck('nilai', 'label');
        $ambil = fn (string $label): int => (int) ($nilai->get($label) ?? 0);

        $terjadwal = $ambil('Terjadwal');
        $terisi = $ambil('Terisi');
        $belum = $ambil('Belum');
        $berhalangan = $ambil('Berhalangan');
        $penyebut = $terjadwal - $berhalangan;

        $guruBelum = collect($hasil['belum_terisi'])
            ->map(fn (array $b): array => [
                'inisial' => self::inisial((string) $b['nama']),
                'nama' => $b['nama'],
                'kelas' => $b['kelas'],
                'mapel' => $b['mapel'],
                'label_jam' => $b['label_jam'],
            ])
            ->values()
            ->all();

        return [
            'tersedia' => true,
            'terjadwal' => $terjadwal,
            'terisi' => $terisi,
            'belum_terisi' => $belum,
            'berhalangan' => $berhalangan,
            'persen' => $penyebut > 0 ? round(100 * $terisi / $penyebut, 1) : null,
            'guru_belum' => $guruBelum,
            'presensi_siswa' => $presensiSiswa,
        ];
    }

    /** FR-TV-07 — ringkasan presensi siswa hari ini (agregat, tanpa nama siswa). */
    private function presensiSiswa(CarbonImmutable $saat): array
    {
        $jumlah = PresensiSiswa::query()
            ->join('jurnal', 'jurnal.id', '=', 'presensi_siswa.jurnal_id')
            ->whereDate('jurnal.tanggal', $saat->toDateString())
            ->selectRaw('presensi_siswa.status as status, COUNT(*) as jumlah')
            ->groupBy('presensi_siswa.status')
            ->pluck('jumlah', 'status');

        $h = (int) ($jumlah->get(PresensiSiswa::HADIR) ?? 0);
        $s = (int) ($jumlah->get(PresensiSiswa::SAKIT) ?? 0);
        $i = (int) ($jumlah->get(PresensiSiswa::IZIN) ?? 0);
        $a = (int) ($jumlah->get(PresensiSiswa::ALPA) ?? 0);
        $total = $h + $s + $i + $a;

        return [
            'hadir' => $h,
            'sakit' => $s,
            'izin' => $i,
            'alpa' => $a,
            'total' => $total,
            'persen_hadir' => $total > 0 ? round(100 * $h / $total, 1) : null,
        ];
    }

    /** FR-TV-08 — kolom perizinan; angka dari laporan rekap izin (BR-37). */
    private function kolomPerizinan(): array
    {
        $hasil = $this->presensi->rekapIzin(PeriodeLaporan::buat(['periode' => 'hari_ini']));
        $tampilkanAlasan = (bool) $this->pengaturan->ambil('tv_tampilkan_alasan_izin');

        $daftar = collect($hasil['detail'])
            ->map(function (array $d) use ($tampilkanAlasan): array {
                $baris = [
                    'inisial' => self::inisial((string) $d['nama']),
                    'nama' => $d['nama'],
                    'jenis' => $d['jenis'],
                    'label_jenis' => $d['label_jenis'],
                    'sampai' => $d['tanggal_selesai'],
                ];

                // BR-33: alasan hanya muncul bila opsi diaktifkan.
                if ($tampilkanAlasan) {
                    $baris['alasan'] = $d['alasan'];
                }

                return $baris;
            })
            ->values()
            ->all();

        return [
            'ringkasan' => $hasil['ringkasan'],
            'daftar' => $daftar,
            'tampilkan_alasan' => $tampilkanAlasan,
        ];
    }

    /** FR-PMN-04 — pengingat ulang tahun hari ini (opsional, hanya nama+inisial). */
    private function ulangTahun(CarbonImmutable $saat): array
    {
        if (! (bool) $this->pengaturan->ambil('tv_tampilkan_ulang_tahun')) {
            return ['aktif' => false, 'daftar' => []];
        }

        $daftar = Pegawai::query()
            ->where('is_active', true)
            ->whereMonth('tanggal_lahir', $saat->month)
            ->whereDay('tanggal_lahir', $saat->day)
            ->orderBy('nama')
            ->get(['nama', 'tanggal_lahir'])
            ->map(fn (Pegawai $p): array => [
                'inisial' => self::inisial((string) $p->nama),
                'nama' => $p->nama,
                'tanggal_lahir' => $p->tanggal_lahir?->toDateString(),
            ])
            ->values()
            ->all();

        return ['aktif' => true, 'daftar' => $daftar];
    }

    /** FR-TV-15 / FR-TV-16 — pengaturan tampilan yang dibaca perangkat TV. */
    public function tampilan(): array
    {
        return [
            'interval_detik' => (int) $this->pengaturan->ambil('tv_interval_detik', 30),
            'tema' => (string) $this->pengaturan->ambil('tv_tema', 'gelap'),
            'skala_font' => (string) $this->pengaturan->ambil('tv_skala_font', 'besar'),
            'rotasi_panel_detik' => (int) $this->pengaturan->ambil('tv_rotasi_panel_detik', 10),
            'kecepatan_scroll' => (string) $this->pengaturan->ambil('tv_kecepatan_scroll', 'normal'),
            'tampilkan_alasan_izin' => (bool) $this->pengaturan->ambil('tv_tampilkan_alasan_izin'),
            'tampilkan_ulang_tahun' => (bool) $this->pengaturan->ambil('tv_tampilkan_ulang_tahun'),
        ];
    }

    /** BR-33 — avatar pegawai = huruf inisial, bukan foto. */
    public static function inisial(string $nama): string
    {
        $kata = preg_split('/\s+/', trim($nama)) ?: [];

        $huruf = '';

        foreach ($kata as $k) {
            if ($k === '') {
                continue;
            }

            $huruf .= mb_strtoupper(mb_substr($k, 0, 1));

            if (mb_strlen($huruf) === 2) {
                break;
            }
        }

        return $huruf !== '' ? $huruf : '?';
    }
}
