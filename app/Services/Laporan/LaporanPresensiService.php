<?php

declare(strict_types=1);

namespace App\Services\Laporan;

use App\Models\HariLibur;
use App\Models\JamKerja;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PengajuanLuarRadius;
use App\Models\PresensiPegawai;
use App\Models\Semester;
use App\Services\JamKerjaService;
use App\Services\MonitoringPresensiService;
use App\Support\PeriodeLaporan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 5.14 A — laporan presensi pegawai (FR-LAP-01..05).
 *
 * Aturan yang ditegakkan:
 *  - BR-24 hari kerja = `jam_kerja.is_hari_kerja` untuk jenis pegawai itu DAN bukan
 *    hari libur. Alpa = hari kerja yang sudah LEWAT, pegawai aktif, tanpa presensi
 *    masuk bervalidasi, dan tanpa izin/sakit/cuti/dinas disetujui. Alpa dihitung
 *    saat laporan dibuat, tidak pernah disimpan (FR-LAP-10).
 *  - BR-25 dinas disetujui tetap menuntut presensi; izin/sakit/cuti membebaskan.
 */
final class LaporanPresensiService
{
    public function __construct(
        private readonly JamKerjaService $jamKerja,
        private readonly MonitoringPresensiService $monitoring,
    ) {}

    /**
     * FR-LAP-01 — rekap presensi pegawai per periode.
     *
     * @param  array{jenis_pegawai?: ?string, pegawai_id?: ?int}  $filter
     * @return array<string, mixed>
     */
    public function rekapPegawai(Semester $semester, PeriodeLaporan $periode, array $filter = []): array
    {
        $pegawai = Pegawai::query()
            ->where('is_active', true)
            ->when(! empty($filter['jenis_pegawai']), fn ($q) => $q->where('jenis_pegawai', $filter['jenis_pegawai']))
            ->when(! empty($filter['pegawai_id']), fn ($q) => $q->whereKey((int) $filter['pegawai_id']))
            ->orderBy('nama')
            ->get();

        $id = $pegawai->pluck('id')->map(fn ($v): int => (int) $v)->all();

        $presensi = PresensiPegawai::query()
            ->whereIn('pegawai_id', $id)
            ->where('semester_id', $semester->id)
            ->whereDate('tanggal', '>=', $periode->dari)
            ->whereDate('tanggal', '<=', $periode->sampai)
            ->get()
            ->groupBy('pegawai_id');

        $izin = PengajuanIzin::query()
            ->whereIn('pegawai_id', $id)
            ->where('status', PengajuanIzin::STATUS_DISETUJUI)
            ->where('tanggal_mulai', '<=', $periode->sampai)
            ->where('tanggal_selesai', '>=', $periode->dari)
            ->get()
            ->groupBy('pegawai_id');

        $aturanJam = $this->petaJamKerja();
        $libur = $this->daftarLibur($semester);
        $hariIni = CarbonImmutable::now()->startOfDay();
        $tanggalPeriode = $periode->tanggal();

        $baris = [];
        $total = $this->kolomKosong();

        foreach ($pegawai as $orang) {
            $punyaPresensi = ($presensi->get($orang->getKey()) ?? collect())
                ->keyBy(fn (PresensiPegawai $p): string => $p->tanggal->toDateString());
            $punyaIzin = $izin->get($orang->getKey()) ?? collect();

            $kolom = $this->kolomKosong();

            foreach ($tanggalPeriode as $t) {
                // Alpa hanya dihitung untuk hari yang sudah lewat.
                if ($t->greaterThan($hariIni)) {
                    break;
                }

                if (! $this->hariKerjaPada($aturanJam, $orang->jenis_pegawai, $t, $libur)) {
                    continue;
                }

                $kolom['hari_kerja']++;

                /** @var PresensiPegawai|null $presensiHari */
                $presensiHari = $punyaPresensi->get($t->toDateString());
                $izinHari = $punyaIzin->first(fn (PengajuanIzin $i): bool => $i->mencakupTanggal($t));

                if ($presensiHari !== null && $this->presensiDihitung($presensiHari)) {
                    if ($presensiHari->masuk_status === PresensiPegawai::STATUS_TERLAMBAT) {
                        $kolom['terlambat']++;
                        $kolom['menit_terlambat'] += (int) $presensiHari->masuk_menit_terlambat;
                    } else {
                        $kolom['hadir']++;
                    }

                    if ($presensiHari->masuk_validasi === PresensiPegawai::DISETUJUI
                        && $presensiHari->masuk_lokasi_id === null) {
                        $kolom['luar_radius']++;
                    }

                    if ($presensiHari->pulang_status === PresensiPegawai::PULANG_CEPAT) {
                        $kolom['pulang_cepat']++;
                    }

                    if ($presensiHari->belumPulang()) {
                        $kolom['tidak_presensi_pulang']++;
                    }

                    // BR-25 — dinas menuntut presensi; harinya tetap tercatat sebagai dinas.
                    if ($izinHari?->jenis === PengajuanIzin::JENIS_DINAS) {
                        $kolom['dinas']++;
                    }
                } elseif ($izinHari !== null) {
                    $kolom[$izinHari->jenis]++;
                } else {
                    $kolom['alpa']++;
                }
            }

            $kolom['persen_kehadiran'] = $kolom['hari_kerja'] > 0
                ? round(100 * ($kolom['hadir'] + $kolom['terlambat']) / $kolom['hari_kerja'], 1)
                : null;

            foreach ($kolom as $kunci => $nilai) {
                if ($kunci !== 'persen_kehadiran') {
                    $total[$kunci] += $nilai;
                }
            }

            $baris[] = [
                'pegawai_id' => (int) $orang->getKey(),
                'nip' => $orang->nip,
                'nama' => $orang->nama,
                'jenis_pegawai' => $orang->jenis_pegawai,
                'jabatan' => $orang->jabatan,
                ...$kolom,
            ];
        }

        return [
            'kode' => 'FR-LAP-01',
            'judul' => 'Rekap Presensi Pegawai',
            'periode' => $periode->label,
            'kolom' => [
                'NIP', 'Nama', 'Hari Kerja', 'Hadir', 'Terlambat', 'Menit Terlambat',
                'Pulang Cepat', 'Tanpa Presensi Pulang', 'Izin', 'Sakit', 'Dinas',
                'Cuti', 'Alpa', 'Luar Radius', 'Kehadiran (%)',
            ],
            'baris' => array_map(fn (array $b): array => [
                $b['nip'], $b['nama'], $b['hari_kerja'], $b['hadir'], $b['terlambat'],
                $b['menit_terlambat'], $b['pulang_cepat'], $b['tidak_presensi_pulang'],
                $b['izin'], $b['sakit'], $b['dinas'], $b['cuti'], $b['alpa'],
                $b['luar_radius'], $b['persen_kehadiran'],
            ], $baris),
            'pegawai' => $baris,
            'total' => $total,
            'ringkasan' => [
                ['label' => 'Pegawai', 'nilai' => count($baris)],
                ['label' => 'Hadir', 'nilai' => $total['hadir']],
                ['label' => 'Terlambat', 'nilai' => $total['terlambat']],
                ['label' => 'Alpa', 'nilai' => $total['alpa']],
            ],
        ];
    }

    /**
     * FR-LAP-02 — detail presensi harian seorang pegawai.
     *
     * @return array<string, mixed>
     */
    public function detailPegawai(Semester $semester, Pegawai $pegawai, PeriodeLaporan $periode): array
    {
        $presensi = PresensiPegawai::query()
            ->with(['masukLokasi', 'pulangLokasi'])
            ->where('pegawai_id', $pegawai->getKey())
            ->where('semester_id', $semester->id)
            ->whereDate('tanggal', '>=', $periode->dari)
            ->whereDate('tanggal', '<=', $periode->sampai)
            ->orderBy('tanggal')
            ->get()
            ->keyBy(fn (PresensiPegawai $p): string => $p->tanggal->toDateString());

        $izin = PengajuanIzin::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->where('status', PengajuanIzin::STATUS_DISETUJUI)
            ->where('tanggal_mulai', '<=', $periode->sampai)
            ->where('tanggal_selesai', '>=', $periode->dari)
            ->get();

        $aturanJam = $this->petaJamKerja();
        $libur = $this->daftarLibur($semester);
        $hariIni = CarbonImmutable::now()->startOfDay();

        $baris = [];

        foreach ($periode->tanggal() as $t) {
            if ($t->greaterThan($hariIni)) {
                break;
            }

            if (! $this->hariKerjaPada($aturanJam, $pegawai->jenis_pegawai, $t, $libur)) {
                continue;
            }

            /** @var PresensiPegawai|null $p */
            $p = $presensi->get($t->toDateString());
            $izinHari = $izin->first(fn (PengajuanIzin $i): bool => $i->mencakupTanggal($t));

            if ($p === null && $izinHari === null) {
                continue;
            }

            $baris[] = [
                'tanggal' => $t->toDateString(),
                'hari' => JamKerja::DAFTAR_HARI[$t->dayOfWeekIso] ?? null,
                'jam_masuk' => $p?->masuk_waktu?->format('H:i'),
                'jam_pulang' => $p?->pulang_waktu?->format('H:i'),
                'status_masuk' => $p?->masuk_status,
                'status_pulang' => $p?->pulang_status,
                'jarak_m' => $p?->masuk_jarak_m,
                'lokasi' => $p?->masukLokasi?->nama,
                'validasi' => $p?->masuk_validasi,
                'keterangan' => $p?->masuk_alasan_luar_radius
                    ?? ($izinHari ? $izinHari->labelJenis().' disetujui' : null),
            ];
        }

        return [
            'kode' => 'FR-LAP-02',
            'judul' => 'Detail Presensi Harian — '.$pegawai->nama,
            'periode' => $periode->label,
            'kolom' => ['Tanggal', 'Hari', 'Jam Masuk', 'Jam Pulang', 'Status Masuk', 'Status Pulang', 'Jarak (m)', 'Lokasi', 'Validasi', 'Keterangan'],
            'baris' => array_map(fn (array $b): array => [
                $b['tanggal'], $b['hari'], $b['jam_masuk'], $b['jam_pulang'],
                $b['status_masuk'], $b['status_pulang'], $b['jarak_m'], $b['lokasi'],
                $b['validasi'], $b['keterangan'],
            ], $baris),
            'detail' => $baris,
            'pegawai' => ['pegawai_id' => (int) $pegawai->getKey(), 'nip' => $pegawai->nip, 'nama' => $pegawai->nama],
            'ringkasan' => [['label' => 'Baris', 'nilai' => count($baris)]],
        ];
    }

    /**
     * FR-LAP-03 — presensi harian seluruh pegawai untuk satu tanggal.
     *
     * Perhitungan status memakai layanan monitoring yang sama agar laporan dan
     * layar monitoring tidak pernah berbeda (BR-37).
     *
     * @return array<string, mixed>
     */
    public function harian(string $tanggal, ?string $jenisPegawai = null, ?int $pegawaiId = null): array
    {
        $hasil = $this->monitoring->harian($tanggal, $jenisPegawai);

        $baris = collect($hasil['baris'])
            ->when($pegawaiId !== null, fn (Collection $c): Collection => $c->where('pegawai_id', $pegawaiId))
            ->values();

        $kolom = ['NIP', 'Nama', 'Jenis', 'Status', 'Jam Masuk', 'Jam Pulang', 'Menit Terlambat', 'Lokasi', 'Pengajuan'];

        return [
            'kode' => 'FR-LAP-03',
            'judul' => 'Presensi Harian Pegawai',
            'periode' => PeriodeLaporan::tanggalPanjang(CarbonImmutable::parse($tanggal)),
            'kolom' => $kolom,
            'baris' => $baris->map(fn (array $b): array => [
                $b['nip'], $b['nama'], $b['jenis_pegawai'], $b['label_status'],
                $b['masuk_jam'], $b['pulang_jam'], $b['menit_terlambat'],
                $b['lokasi'], $b['pengajuan_label'],
            ])->all(),
            'detail' => $baris->all(),
            'tanggal' => $hasil['tanggal'],
            'nama_hari' => $hasil['nama_hari'],
            'hari_libur' => $hasil['hari_libur'],
            'ringkasan' => collect(MonitoringPresensiService::DAFTAR_STATUS)
                ->map(fn (string $label, string $kode): array => [
                    'label' => $label,
                    'nilai' => (int) ($hasil['ringkasan'][$kode] ?? 0),
                ])->values()->all(),
        ];
    }

    /**
     * FR-LAP-04 — rekap izin/sakit/dinas/cuti per periode.
     *
     * @param  array{pegawai_id?: ?int, jenis?: ?string}  $filter
     * @return array<string, mixed>
     */
    public function rekapIzin(PeriodeLaporan $periode, array $filter = []): array
    {
        $pengajuan = PengajuanIzin::query()
            ->with('pegawai:id,nip,nama,jenis_pegawai')
            ->where('status', PengajuanIzin::STATUS_DISETUJUI)
            ->where('tanggal_mulai', '<=', $periode->sampai)
            ->where('tanggal_selesai', '>=', $periode->dari)
            ->when(! empty($filter['jenis']), fn ($q) => $q->where('jenis', $filter['jenis']))
            ->when(! empty($filter['pegawai_id']), fn ($q) => $q->where('pegawai_id', (int) $filter['pegawai_id']))
            ->orderBy('tanggal_mulai')
            ->get();

        $perPegawai = [];
        $detail = [];

        foreach ($pengajuan as $p) {
            $id = (int) $p->pegawai_id;
            $perPegawai[$id] ??= [
                'pegawai_id' => $id,
                'nip' => $p->pegawai?->nip,
                'nama' => $p->pegawai?->nama,
                'jenis_pegawai' => $p->pegawai?->jenis_pegawai,
                'izin' => 0, 'sakit' => 0, 'dinas' => 0, 'cuti' => 0,
                'jumlah_pengajuan' => 0, 'total_hari' => 0,
            ];

            $hari = $this->hariTumpangTindih($p, $periode);

            $perPegawai[$id][$p->jenis] += $hari;
            $perPegawai[$id]['jumlah_pengajuan']++;
            $perPegawai[$id]['total_hari'] += $hari;

            $detail[] = [
                'tanggal_mulai' => $p->tanggal_mulai?->toDateString(),
                'tanggal_selesai' => $p->tanggal_selesai?->toDateString(),
                'nip' => $p->pegawai?->nip,
                'nama' => $p->pegawai?->nama,
                'jenis' => $p->jenis,
                'label_jenis' => $p->labelJenis(),
                'hari' => $hari,
                'alasan' => $p->alasan,
            ];
        }

        $baris = array_values($perPegawai);

        return [
            'kode' => 'FR-LAP-04',
            'judul' => 'Rekap Izin, Sakit, Dinas & Cuti',
            'periode' => $periode->label,
            'kolom' => ['NIP', 'Nama', 'Jenis Pegawai', 'Izin (hari)', 'Sakit (hari)', 'Dinas (hari)', 'Cuti (hari)', 'Pengajuan', 'Total Hari'],
            'baris' => array_map(fn (array $b): array => [
                $b['nip'], $b['nama'], $b['jenis_pegawai'], $b['izin'], $b['sakit'],
                $b['dinas'], $b['cuti'], $b['jumlah_pengajuan'], $b['total_hari'],
            ], $baris),
            'pegawai' => $baris,
            'detail' => $detail,
            'ringkasan' => [
                ['label' => 'Pengajuan', 'nilai' => count($detail)],
                ['label' => 'Izin', 'nilai' => array_sum(array_column($baris, 'izin'))],
                ['label' => 'Sakit', 'nilai' => array_sum(array_column($baris, 'sakit'))],
                ['label' => 'Dinas', 'nilai' => array_sum(array_column($baris, 'dinas'))],
                ['label' => 'Cuti', 'nilai' => array_sum(array_column($baris, 'cuti'))],
            ],
        ];
    }

    /**
     * FR-LAP-05 — rekap presensi luar radius menurut status keputusan.
     *
     * @param  array{pegawai_id?: ?int, status?: ?string}  $filter
     * @return array<string, mixed>
     */
    public function rekapLuarRadius(PeriodeLaporan $periode, array $filter = []): array
    {
        $pengajuan = PengajuanLuarRadius::query()
            ->with(['pegawai:id,nip,nama', 'diputuskanOleh:id,name'])
            ->whereDate('tanggal', '>=', $periode->dari)
            ->whereDate('tanggal', '<=', $periode->sampai)
            ->when(! empty($filter['status']), fn ($q) => $q->where('status', $filter['status']))
            ->when(! empty($filter['pegawai_id']), fn ($q) => $q->where('pegawai_id', (int) $filter['pegawai_id']))
            ->orderBy('tanggal')
            ->get();

        $baris = $pengajuan->map(fn (PengajuanLuarRadius $p): array => [
            'tanggal' => $p->tanggal?->toDateString(),
            'nip' => $p->pegawai?->nip,
            'nama' => $p->pegawai?->nama,
            'alasan' => $p->alasan,
            'status' => $p->status,
            'label_status' => PengajuanLuarRadius::DAFTAR_STATUS[$p->status] ?? $p->status,
            'diputuskan_oleh' => $p->diputuskanOleh?->name,
        ])->all();

        $hitung = collect(PengajuanLuarRadius::DAFTAR_STATUS)
            ->map(fn (string $label, string $kode): int => collect($baris)->where('status', $kode)->count());

        return [
            'kode' => 'FR-LAP-05',
            'judul' => 'Rekap Presensi Luar Radius',
            'periode' => $periode->label,
            'kolom' => ['Tanggal', 'NIP', 'Nama', 'Alasan', 'Status', 'Diputuskan Oleh'],
            'baris' => array_map(fn (array $b): array => [
                $b['tanggal'], $b['nip'], $b['nama'], $b['alasan'], $b['label_status'], $b['diputuskan_oleh'],
            ], $baris),
            'detail' => $baris,
            'ringkasan' => collect(PengajuanLuarRadius::DAFTAR_STATUS)
                ->map(fn (string $label, string $kode): array => [
                    'label' => $label,
                    'nilai' => (int) ($hitung->get($kode) ?? 0),
                ])->values()->all(),
        ];
    }

    // =====================================================================
    // Bagian dalam
    // =====================================================================

    /** @return array<string, int|float|null> */
    private function kolomKosong(): array
    {
        return [
            'hari_kerja' => 0,
            'hadir' => 0,
            'terlambat' => 0,
            'menit_terlambat' => 0,
            'pulang_cepat' => 0,
            'tidak_presensi_pulang' => 0,
            'izin' => 0,
            'sakit' => 0,
            'dinas' => 0,
            'cuti' => 0,
            'alpa' => 0,
            'luar_radius' => 0,
        ];
    }

    /** Presensi yang dihitung hadir: masuk ada dan validasinya termasuk dihitung (BR-18). */
    private function presensiDihitung(PresensiPegawai $p): bool
    {
        return $p->masuk_waktu !== null
            && in_array($p->masuk_validasi, PresensiPegawai::VALIDASI_DIHITUNG, true);
    }

    /** @return Collection<string, JamKerja> */
    private function petaJamKerja(): Collection
    {
        return JamKerja::query()->get()->keyBy(fn (JamKerja $j): string => $j->jenis_pegawai.'-'.$j->hari);
    }

    /** @return Collection<int, HariLibur> */
    private function daftarLibur(Semester $semester): Collection
    {
        return HariLibur::query()
            ->where('tahun_pelajaran_id', $semester->tahun_pelajaran_id)
            ->get();
    }

    /**
     * BR-24 — hari kerja: jam kerja menandai hari itu sebagai hari kerja DAN
     * tanggal tersebut tidak masuk rentang hari libur.
     *
     * @param  Collection<string, JamKerja>  $aturan
     * @param  Collection<int, HariLibur>  $libur
     */
    private function hariKerjaPada(Collection $aturan, string $jenisPegawai, CarbonImmutable $tanggal, Collection $libur): bool
    {
        $baris = $aturan->get($jenisPegawai.'-'.$tanggal->dayOfWeekIso);

        if ($baris === null || ! $baris->is_hari_kerja) {
            return false;
        }

        foreach ($libur as $l) {
            if ($tanggal->greaterThanOrEqualTo($l->tanggal_mulai)
                && $tanggal->lessThanOrEqualTo($l->tanggal_selesai)) {
                return false;
            }
        }

        return true;
    }

    /** Jumlah hari pengajuan yang benar-benar jatuh di dalam periode. */
    private function hariTumpangTindih(PengajuanIzin $p, PeriodeLaporan $periode): int
    {
        $mulai = $p->tanggal_mulai->greaterThan($periode->dari) ? $p->tanggal_mulai : $periode->dari;
        $selesai = $p->tanggal_selesai->lessThan($periode->sampai) ? $p->tanggal_selesai : $periode->sampai;

        if ($selesai->lessThan($mulai)) {
            return 0;
        }

        return (int) $mulai->diffInDays($selesai) + 1;
    }
}
