<?php

declare(strict_types=1);

namespace App\Services\Laporan;

use App\Models\JamKerja;
use App\Models\Jurnal;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PresensiSiswa;
use App\Models\Semester;
use App\Services\JamKerjaService;
use App\Services\JurnalService;
use App\Support\PeriodeLaporan;
use Carbon\CarbonImmutable;

/**
 * 5.14 B — laporan jurnal & presensi siswa (FR-LAP-06..09).
 *
 * Aturan yang ditegakkan:
 *  - FR-LAP-06 rekap presensi siswa diturunkan dari jurnal (per kelas × periode,
 *    dapat difilter per mapel).
 *  - BR-26 kepatuhan jurnal: sesi pada hari izin/sakit/cuti/dinas disetujui
 *    berstatus "Berhalangan" dan DIKECUALIKAN dari hitungan belum terisi; sesi
 *    pada hari libur juga tidak dihitung. Penyebutnya hanya sesi terjadwal pada
 *    hari kerja non-libur (KP-5.4).
 */
final class LaporanJurnalService
{
    public function __construct(
        private readonly JurnalService $jurnal,
        private readonly JamKerjaService $jamKerja,
    ) {}

    /**
     * FR-LAP-06 — rekap presensi siswa satu kelas dari jurnal.
     *
     * @return array<string, mixed>
     */
    public function rekapSiswa(Semester $semester, int $kelasId, ?string $namaKelas, PeriodeLaporan $periode, ?int $mapelId = null): array
    {
        $hasil = $this->jurnal->rekapPresensiSiswa(
            $semester,
            $kelasId,
            $periode->dari->toDateString(),
            $periode->sampai->toDateString(),
            $mapelId,
        );

        $siswa = $hasil['siswa'];

        return [
            'kode' => 'FR-LAP-06',
            'judul' => 'Rekap Presensi Siswa'.($namaKelas ? ' — '.$namaKelas : ''),
            'periode' => $periode->label,
            'kolom' => ['NIS', 'Nama', 'Hadir (H)', 'Sakit (S)', 'Izin (I)', 'Alpa (A)', 'Total', 'Hadir (%)'],
            'baris' => array_map(fn (array $b): array => [
                $b['nis'], $b['nama'], $b['hadir'], $b['sakit'], $b['izin'],
                $b['alpa'], $b['total'], $b['persen_hadir'],
            ], $siswa),
            'siswa' => $siswa,
            'kelas_id' => $kelasId,
            'kelas' => $namaKelas,
            'mapel_id' => $mapelId,
            'sesi' => $hasil['sesi'],
            'ringkasan' => [
                ['label' => 'Sesi', 'nilai' => $hasil['sesi']],
                ['label' => 'H', 'nilai' => $hasil['ringkasan'][PresensiSiswa::HADIR]],
                ['label' => 'S', 'nilai' => $hasil['ringkasan'][PresensiSiswa::SAKIT]],
                ['label' => 'I', 'nilai' => $hasil['ringkasan'][PresensiSiswa::IZIN]],
                ['label' => 'A', 'nilai' => $hasil['ringkasan'][PresensiSiswa::ALPA]],
            ],
        ];
    }

    /**
     * FR-LAP-07 — daftar jurnal dengan filter periode/guru/kelas/mapel.
     *
     * @param  array{guru_id?: ?int, kelas_id?: ?int, mapel_id?: ?int}  $filter
     * @return array<string, mixed>
     */
    public function daftarJurnal(Semester $semester, PeriodeLaporan $periode, array $filter = []): array
    {
        $jurnal = Jurnal::query()
            ->with(['kelas:id,nama', 'pegawai:id,nip,nama', 'plottingMapel.mapel:id,kode,nama'])
            ->withCount([
                'presensiSiswa as jumlah_hadir' => fn ($q) => $q->where('status', PresensiSiswa::HADIR),
                'presensiSiswa as jumlah_sakit' => fn ($q) => $q->where('status', PresensiSiswa::SAKIT),
                'presensiSiswa as jumlah_izin' => fn ($q) => $q->where('status', PresensiSiswa::IZIN),
                'presensiSiswa as jumlah_alpa' => fn ($q) => $q->where('status', PresensiSiswa::ALPA),
            ])
            ->where('semester_id', $semester->id)
            ->rentangTanggal($periode->dari->toDateString(), $periode->sampai->toDateString())
            ->when(! empty($filter['guru_id']), fn ($q) => $q->where('pegawai_id', (int) $filter['guru_id']))
            ->when(! empty($filter['kelas_id']), fn ($q) => $q->where('kelas_id', (int) $filter['kelas_id']))
            ->when(! empty($filter['mapel_id']), fn ($q) => $q->whereHas(
                'plottingMapel',
                fn ($m) => $m->where('mapel_id', (int) $filter['mapel_id'])
            ))
            ->orderBy('tanggal')
            ->orderBy('jam_ke_mulai')
            ->get();

        $baris = $jurnal->map(fn (Jurnal $j): array => [
            'tanggal' => $j->tanggal?->toDateString(),
            'label_jam' => $j->labelJamKe(),
            'kelas' => $j->kelas?->nama,
            'mapel' => $j->plottingMapel?->mapel?->nama,
            'guru' => $j->pegawai?->nama,
            'nip' => $j->pegawai?->nip,
            'materi' => $j->materi,
            'kegiatan' => $j->kegiatan,
            'hadir' => (int) $j->jumlah_hadir,
            'sakit' => (int) $j->jumlah_sakit,
            'izin' => (int) $j->jumlah_izin,
            'alpa' => (int) $j->jumlah_alpa,
        ])->all();

        return [
            'kode' => 'FR-LAP-07',
            'judul' => 'Daftar Jurnal Pembelajaran',
            'periode' => $periode->label,
            'kolom' => ['Tanggal', 'Jam Ke', 'Kelas', 'Mapel', 'Guru', 'Materi', 'Kegiatan', 'H', 'S', 'I', 'A'],
            'baris' => array_map(fn (array $b): array => [
                $b['tanggal'], $b['label_jam'], $b['kelas'], $b['mapel'], $b['guru'],
                $b['materi'], $b['kegiatan'], $b['hadir'], $b['sakit'], $b['izin'], $b['alpa'],
            ], $baris),
            'jurnal' => $baris,
            'ringkasan' => [
                ['label' => 'Jurnal', 'nilai' => count($baris)],
                ['label' => 'H', 'nilai' => array_sum(array_column($baris, 'hadir'))],
                ['label' => 'S', 'nilai' => array_sum(array_column($baris, 'sakit'))],
                ['label' => 'I', 'nilai' => array_sum(array_column($baris, 'izin'))],
                ['label' => 'A', 'nilai' => array_sum(array_column($baris, 'alpa'))],
            ],
        ];
    }

    /**
     * FR-LAP-08 / BR-26 — rekap kepatuhan jurnal per guru.
     *
     * Penyebut = sesi terjadwal pada hari kerja non-libur DIKURANGI sesi
     * berhalangan. Sesi pada hari libur dan hari berhalangan tidak pernah masuk
     * hitungan "belum terisi" (KP-5.4).
     *
     * @param  array{guru_id?: ?int}  $filter
     * @return array<string, mixed>
     */
    public function kepatuhanJurnal(Semester $semester, PeriodeLaporan $periode, array $filter = []): array
    {
        $guru = Pegawai::query()
            ->where('is_active', true)
            ->where('jenis_pegawai', Pegawai::JENIS_GURU)
            ->when(! empty($filter['guru_id']), fn ($q) => $q->whereKey((int) $filter['guru_id']))
            ->orderBy('nama')
            ->get();

        $idGuru = $guru->pluck('id')->map(fn ($v): int => (int) $v)->all();

        // Jadwal, jurnal, dan izin diambil sekali lalu diolah di memori — jauh
        // lebih murah daripada tiga query per (guru × tanggal).
        $jadwal = $this->jurnal->sesiPersiapanJadwal($semester, $idGuru);
        $sudah = Jurnal::query()
            ->where('semester_id', $semester->id)
            ->whereIn('pegawai_id', $idGuru)
            ->rentangTanggal($periode->dari->toDateString(), $periode->sampai->toDateString())
            ->get(['pegawai_id', 'plotting_mapel_id', 'tanggal', 'jam_ke_mulai'])
            ->keyBy(fn (Jurnal $j): string => $j->pegawai_id.'|'.$j->tanggal->toDateString().'|'.$j->plotting_mapel_id.'|'.$j->jam_ke_mulai);

        $izin = PengajuanIzin::query()
            ->whereIn('pegawai_id', $idGuru)
            ->where('status', PengajuanIzin::STATUS_DISETUJUI)
            ->where('tanggal_mulai', '<=', $periode->sampai)
            ->where('tanggal_selesai', '>=', $periode->dari)
            ->get()
            ->groupBy('pegawai_id');

        $hariIni = CarbonImmutable::now()->startOfDay();
        $tanggal = $periode->tanggal();

        $baris = [];
        $belumTerisi = [];

        foreach ($guru as $orang) {
            $perHari = $jadwal->get($orang->getKey(), collect());
            $izinGuru = $izin->get($orang->getKey()) ?? collect();

            $terjadwal = 0;
            $terisi = 0;
            $berhalangan = 0;
            $belum = 0;

            foreach ($tanggal as $t) {
                if ($t->greaterThan($hariIni)) {
                    break;
                }

                if (! $this->jamKerja->hariKerja($orang->jenis_pegawai, $t)) {
                    continue;
                }

                $sesiHari = $this->jurnal->gabungSesi($perHari->get($t->dayOfWeekIso, collect()));

                if ($sesiHari === []) {
                    continue;
                }

                $izinHari = $izinGuru->first(fn (PengajuanIzin $i): bool => $i->mencakupTanggal($t));

                foreach ($sesiHari as $s) {
                    $terjadwal++;

                    // BR-26 — hari berhalangan: sesi dikecualikan dari "belum terisi".
                    if ($izinHari !== null) {
                        $berhalangan++;

                        continue;
                    }

                    $kunci = $orang->getKey().'|'.$t->toDateString().'|'.$s['plotting_mapel_id'].'|'.$s['jam_ke_mulai'];

                    if ($sudah->has($kunci)) {
                        $terisi++;

                        continue;
                    }

                    $belum++;
                    $belumTerisi[] = [
                        'tanggal' => $t->toDateString(),
                        'hari' => JamKerja::DAFTAR_HARI[$t->dayOfWeekIso] ?? null,
                        'nip' => $orang->nip,
                        'nama' => $orang->nama,
                        'kelas' => $s['kelas'],
                        'mapel' => $s['mapel'],
                        'label_jam' => $this->jurnal->labelJamKe((int) $s['jam_ke_mulai'], (int) $s['jam_ke_selesai']),
                    ];
                }
            }

            $penyebut = $terjadwal - $berhalangan;

            $baris[] = [
                'pegawai_id' => (int) $orang->getKey(),
                'nip' => $orang->nip,
                'nama' => $orang->nama,
                'terjadwal' => $terjadwal,
                'terisi' => $terisi,
                'belum_terisi' => $belum,
                'berhalangan' => $berhalangan,
                'persen_kepatuhan' => $penyebut > 0 ? round(100 * $terisi / $penyebut, 1) : null,
            ];
        }

        return [
            'kode' => 'FR-LAP-08',
            'judul' => 'Rekap Kepatuhan Jurnal per Guru',
            'periode' => $periode->label,
            'kolom' => ['NIP', 'Nama', 'Sesi Terjadwal', 'Terisi', 'Belum Terisi', 'Berhalangan', 'Kepatuhan (%)'],
            'baris' => array_map(fn (array $b): array => [
                $b['nip'], $b['nama'], $b['terjadwal'], $b['terisi'],
                $b['belum_terisi'], $b['berhalangan'], $b['persen_kepatuhan'],
            ], $baris),
            'guru' => $baris,
            'belum_terisi' => $belumTerisi,
            'ringkasan' => [
                ['label' => 'Guru', 'nilai' => count($baris)],
                ['label' => 'Terjadwal', 'nilai' => array_sum(array_column($baris, 'terjadwal'))],
                ['label' => 'Terisi', 'nilai' => array_sum(array_column($baris, 'terisi'))],
                ['label' => 'Belum', 'nilai' => array_sum(array_column($baris, 'belum_terisi'))],
                ['label' => 'Berhalangan', 'nilai' => array_sum(array_column($baris, 'berhalangan'))],
            ],
        ];
    }

    /**
     * FR-LAP-09 — rekap jam mengajar terlaksana per guru (jumlah JP terlaksana).
     *
     * @param  array{guru_id?: ?int}  $filter
     * @return array<string, mixed>
     */
    public function jamMengajar(Semester $semester, PeriodeLaporan $periode, array $filter = []): array
    {
        $guru = Pegawai::query()
            ->where('is_active', true)
            ->where('jenis_pegawai', Pegawai::JENIS_GURU)
            ->when(! empty($filter['guru_id']), fn ($q) => $q->whereKey((int) $filter['guru_id']))
            ->orderBy('nama')
            ->get();

        $jurnal = Jurnal::query()
            ->where('semester_id', $semester->id)
            ->rentangTanggal($periode->dari->toDateString(), $periode->sampai->toDateString())
            ->get(['pegawai_id', 'jam_ke_mulai', 'jam_ke_selesai'])
            ->groupBy('pegawai_id');

        $baris = $guru->map(function (Pegawai $orang) use ($jurnal): array {
            $milik = $jurnal->get($orang->getKey()) ?? collect();

            $jp = $milik->sum(fn (Jurnal $j): int => (int) $j->jam_ke_selesai - (int) $j->jam_ke_mulai + 1);

            return [
                'pegawai_id' => (int) $orang->getKey(),
                'nip' => $orang->nip,
                'nama' => $orang->nama,
                'jumlah_sesi' => $milik->count(),
                'jumlah_jp' => (int) $jp,
            ];
        })->values()->all();

        return [
            'kode' => 'FR-LAP-09',
            'judul' => 'Rekap Jam Mengajar Terlaksana',
            'periode' => $periode->label,
            'kolom' => ['NIP', 'Nama', 'Jumlah Sesi', 'Jumlah JP Terlaksana'],
            'baris' => array_map(fn (array $b): array => [
                $b['nip'], $b['nama'], $b['jumlah_sesi'], $b['jumlah_jp'],
            ], $baris),
            'guru' => $baris,
            'ringkasan' => [
                ['label' => 'Guru', 'nilai' => count($baris)],
                ['label' => 'Sesi', 'nilai' => array_sum(array_column($baris, 'jumlah_sesi'))],
                ['label' => 'JP', 'nilai' => array_sum(array_column($baris, 'jumlah_jp'))],
            ],
        ];
    }
}
