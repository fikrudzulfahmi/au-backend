<?php

declare(strict_types=1);

namespace App\Services\Laporan;

use App\Models\Jadwal;
use App\Models\JamKerja;
use App\Models\Jurnal;
use App\Models\Kelas;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PresensiPegawai;
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
            'kolom' => ['NIS', 'Nama', 'Hadir (H)', 'Sakit (S)', 'Izin (I)', 'Alpa (A)', 'Bolos (B)', 'Total', 'Hadir (%)'],
            'baris' => array_map(fn (array $b): array => [
                $b['nis'], $b['nama'], $b['hadir'], $b['sakit'], $b['izin'],
                $b['alpa'], $b['bolos'], $b['total'], $b['persen_hadir'],
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
                ['label' => 'B', 'nilai' => $hasil['ringkasan'][PresensiSiswa::BOLOS]],
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
                'presensiSiswa as jumlah_bolos' => fn ($q) => $q->where('status', PresensiSiswa::BOLOS),
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
            'bolos' => (int) $j->jumlah_bolos,
        ])->all();

        return [
            'kode' => 'FR-LAP-07',
            'judul' => 'Daftar Jurnal Pembelajaran',
            'periode' => $periode->label,
            'kolom' => ['Tanggal', 'Jam Ke', 'Kelas', 'Mapel', 'Guru', 'Materi', 'Kegiatan', 'H', 'S', 'I', 'A', 'B'],
            'baris' => array_map(fn (array $b): array => [
                $b['tanggal'], $b['label_jam'], $b['kelas'], $b['mapel'], $b['guru'],
                $b['materi'], $b['kegiatan'], $b['hadir'], $b['sakit'], $b['izin'], $b['alpa'], $b['bolos'],
            ], $baris),
            'jurnal' => $baris,
            'ringkasan' => [
                ['label' => 'Jurnal', 'nilai' => count($baris)],
                ['label' => 'H', 'nilai' => array_sum(array_column($baris, 'hadir'))],
                ['label' => 'S', 'nilai' => array_sum(array_column($baris, 'sakit'))],
                ['label' => 'I', 'nilai' => array_sum(array_column($baris, 'izin'))],
                ['label' => 'A', 'nilai' => array_sum(array_column($baris, 'alpa'))],
                ['label' => 'B', 'nilai' => array_sum(array_column($baris, 'bolos'))],
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

    /**
     * Dasbor harian pengisian jurnal — kelas mana yang sesinya sudah diisi guru,
     * mana yang masih kosong, dan mana yang berhalangan (izin disetujui).
     *
     * Berbeda dari `kepatuhanJurnal` yang menghitung per guru × periode, ini
     * memotong satu tanggal dan mengelompokkan hasilnya per kelas, karena yang
     * dicari pengguna adalah "kelas mana yang belum terisi hari ini".
     *
     * Dua penegasan penting bagi pemantau:
     *  - SELURUH kelas ikut tampil, termasuk yang tidak punya sesi pada tanggal
     *    itu (ditandai `tanpa_jadwal`) — kelas yang hilang dari daftar terbaca
     *    seolah aman;
     *  - sesi yang SUDAH ada jurnalnya tetap berstatus "sudah" walaupun guru
     *    pengampunya sedang izin (alur guru piket), sesuai urutan pemeriksaan di
     *    bawah; `FR-IZN-07`/`BR-26` hanya mengatur sesi yang belum terisi.
     *
     * Semua data diambil sekali lalu diolah di memori — sama seperti
     * `kepatuhanJurnal` — supaya jumlah query tetap konstan walau jumlah guru
     * dan kelas bertambah.
     *
     * @return array<string, mixed>
     */
    public function harianKelas(Semester $semester, string $tanggal, ?int $kelasId = null): array
    {
        $hari = CarbonImmutable::parse($tanggal)->isoWeekday();
        $t = CarbonImmutable::parse($tanggal);

        // Hanya guru aktif: jadwal guru yang sudah nonaktif tidak dicetak di dasbor.
        $jadwalPerGuru = Jadwal::query()
            ->with([
                'slotJam',
                'pegawai:id,nip,nama',
                'plottingMapel.mapel:id,kode,nama',
                'plottingMapel.kelas:id,nama',
            ])
            ->where('semester_id', $semester->id)
            ->where('hari', $hari)
            ->when($kelasId !== null, fn ($q) => $q->where('kelas_id', $kelasId))
            ->whereHas('pegawai', function ($q): void {
                $q->where('is_active', true)
                    ->where('jenis_pegawai', Pegawai::JENIS_GURU);
            })
            ->get()
            ->groupBy(fn (Jadwal $j): int => (int) $j->pegawai_id);

        $idGuru = $jadwalPerGuru->keys()->map(fn ($v): int => (int) $v)->all();

        // Kunci unik sesi = plotting mapel + jam ke mulai (BR-21), jadi cukup
        // tanpa pegawai_id.
        $sudah = Jurnal::query()
            ->where('semester_id', $semester->id)
            ->whereDate('tanggal', $tanggal)
            ->get(['id', 'plotting_mapel_id', 'jam_ke_mulai', 'materi'])
            ->keyBy(fn (Jurnal $j): string => $j->plotting_mapel_id.':'.$j->jam_ke_mulai);

        // Rentang tanggal dibandingkan sebagai TANGGAL, bukan string: kolomnya
        // `date` sementara nilainya tersimpan bergaya datetime, sehingga
        // perbandingan string biasa membuat izin yang MULAI hari ini tidak
        // pernah cocok (di SQLite — basis data yang dipakai aplikasi ini —
        // "2026-10-16 00:00:00" > "2026-10-16").
        $izin = PengajuanIzin::query()
            ->whereIn('pegawai_id', $idGuru)
            ->where('status', PengajuanIzin::STATUS_DISETUJUI)
            ->whereDate('tanggal_mulai', '<=', $tanggal)
            ->whereDate('tanggal_selesai', '>=', $tanggal)
            ->get()
            ->groupBy(fn (PengajuanIzin $i): int => (int) $i->pegawai_id);

        // BR-19 — guru yang sudah presensi masuk pada tanggal ini; dibaca sekali
        // agar pengguna dapat membedakan "belum presensi" dan "sudah presensi
        // tetapi belum mengisi jurnal".
        $presensi = PresensiPegawai::query()
            ->whereIn('pegawai_id', $idGuru)
            ->whereDate('tanggal', $tanggal)
            ->whereNotNull('masuk_waktu')
            ->whereIn('masuk_validasi', PresensiPegawai::VALIDASI_DIHITUNG)
            ->pluck('pegawai_id')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $sudahPresensi = array_flip($presensi);

        // Seluruh kelas pada TAHUN PELAJARAN semester ini disemai lebih dulu,
        // bukan hanya kelas yang punya sesi hari ini: pertanyaan pemantau adalah
        // "kelas mana yang belum diisi", dan kelas yang hilang dari daftar
        // justru terbaca seolah aman. Dibatasi satu tahun pelajaran agar kelas
        // angkatan lain tidak ikut muncul sebagai "tidak ada jadwal", dan kelas
        // nonaktif ditinggalkan. Kelas tanpa sesi ditandai `tanpa_jadwal`
        // supaya tidak dihitung sebagai sesi belum terisi.
        $semuaKelas = Kelas::query()
            ->untukTahun((int) $semester->tahun_pelajaran_id)
            ->where('is_active', true)
            ->when($kelasId !== null, fn ($q) => $q->whereKey($kelasId))
            ->orderBy('nama')
            ->get(['id', 'nama']);

        $perKelas = [];
        foreach ($semuaKelas as $k) {
            $perKelas[(int) $k->id] = [
                'kelas_id' => (int) $k->id,
                'nama' => $k->nama,
                'sesi' => 0,
                'sudah' => 0,
                'belum' => 0,
                'berhalangan' => 0,
                'tanpa_jadwal' => true,
                'persen_terisi' => null,
                'daftar' => [],
            ];
        }

        foreach ($jadwalPerGuru as $guruId => $daftarJadwal) {
            $guru = $daftarJadwal->first()?->pegawai;
            $izinHari = ($izin->get((int) $guruId) ?? collect())
                ->first(fn (PengajuanIzin $i): bool => $i->mencakupTanggal($t));
            $presensiAda = isset($sudahPresensi[(int) $guruId]);

            foreach ($this->jurnal->gabungSesi($daftarJadwal) as $s) {
                $kunci = $s['plotting_mapel_id'].':'.$s['jam_ke_mulai'];
                $jurnal = $sudah->get($kunci);

                // Jurnal yang ADA menang atas status izin. FR-IZN-07/BR-26
                // mengatur sesi yang belum terisi pada hari izin; begitu
                // sesinya benar-benar terisi — justru inilah alur guru piket
                // menggantikan pengampu yang izin — melaporkannya sebagai
                // "Berhalangan" membuat dasbor berbohong tentang kelas yang
                // sudah diisi.
                if ($jurnal !== null) {
                    $status = JurnalService::SUDAH;
                    $alasan = null;
                } elseif ($izinHari !== null) {
                    $status = JurnalService::BERHALANGAN;
                    $alasan = 'Berhalangan — '.$izinHari->labelJenis().' disetujui.';
                } else {
                    $status = JurnalService::BELUM;
                    $alasan = $presensiAda ? null : 'Belum presensi masuk';
                }

                $kelasId = (int) $s['kelas_id'];
                $perKelas[$kelasId] ??= [
                    'kelas_id' => $kelasId,
                    'nama' => $s['kelas'],
                    'sesi' => 0,
                    'sudah' => 0,
                    'belum' => 0,
                    'berhalangan' => 0,
                    'tanpa_jadwal' => true,
                    'persen_terisi' => null,
                    'daftar' => [],
                ];
                $perKelas[$kelasId]['tanpa_jadwal'] = false;

                $perKelas[$kelasId]['sesi']++;
                $perKelas[$kelasId][$status === JurnalService::SUDAH
                    ? 'sudah'
                    : ($status === JurnalService::BERHALANGAN ? 'berhalangan' : 'belum')]++;

                $perKelas[$kelasId]['daftar'][] = [
                    'plotting_mapel_id' => (int) $s['plotting_mapel_id'],
                    'label_jam' => $this->jurnal->labelJamKe((int) $s['jam_ke_mulai'], (int) $s['jam_ke_selesai']),
                    'jam_ke_mulai' => (int) $s['jam_ke_mulai'],
                    'jam_ke_selesai' => (int) $s['jam_ke_selesai'],
                    'mapel' => $s['mapel'],
                    'kode_mapel' => $s['kode_mapel'],
                    'guru_id' => (int) $guruId,
                    'nip' => $guru?->nip,
                    'guru' => $guru?->nama,
                    'jurnal_id' => $jurnal?->id,
                    'materi' => $jurnal?->materi,
                    'status' => $status,
                    'alasan' => $alasan,
                ];
            }
        }

        $kelas = collect($perKelas)
            ->map(function (array $k): array {
                usort($k['daftar'], fn (array $a, array $b): int => $a['jam_ke_mulai'] <=> $b['jam_ke_mulai']);

                // Persentase per kelas memakai aturan yang sama dengan angka
                // sekolah (BR-26): sesi berhalangan keluar dari penyebut.
                $penyebut = $k['sesi'] - $k['berhalangan'];
                $k['persen_terisi'] = $penyebut > 0 ? round(100 * $k['sudah'] / $penyebut, 1) : null;

                return $k;
            })
            ->sortBy(fn (array $k): string => (string) $k['nama'])
            ->values()
            ->all();

        $jumlah = fn (string $kunci): int => array_sum(array_column($kelas, $kunci));
        $sesi = $jumlah('sesi');
        $berhalangan = $jumlah('berhalangan');
        $hitung = [
            'kelas' => count($kelas),
            'sesi' => $sesi,
            'sudah' => $jumlah('sudah'),
            'belum' => $jumlah('belum'),
            'berhalangan' => $berhalangan,
            // BR-26 — sesi berhalangan dikeluarkan dari penyebut persentase.
            'persen_terisi' => ($sesi - $berhalangan) > 0
                ? round(100 * $jumlah('sudah') / ($sesi - $berhalangan), 1)
                : null,
        ];

        $labelStatus = [
            JurnalService::SUDAH => 'Sudah diisi',
            JurnalService::BELUM => 'Belum terisi',
            JurnalService::BERHALANGAN => 'Berhalangan',
        ];

        // Satu baris per sesi untuk cetak/ekspor; web membaca `kelas` untuk
        // tampilan berkelompok, cetak membaca `kolom`/`baris` seperti laporan lain.
        $baris = [];
        foreach ($kelas as $k) {
            foreach ($k['daftar'] as $s) {
                $baris[] = [
                    $k['nama'],
                    $s['label_jam'],
                    $s['mapel'],
                    $s['guru'],
                    $labelStatus[$s['status']] ?? $s['status'],
                    $s['alasan'] ?? $s['materi'] ?? '-',
                ];
            }

            // Kelas tanpa sesi pada tanggal ini tetap muncul sebagai satu baris
            // ("semua kelas" diminta secara eksplisit), tetapi diberi label
            // sendiri supaya tidak pernah terbaca sebagai jurnal yang bolong.
            if ($k['sesi'] === 0) {
                $baris[] = [$k['nama'], '-', '-', '-', 'Tidak ada jadwal', 'Tidak ada sesi terjadwal pada hari ini'];
            }
        }

        return [
            'kode' => 'FR-LAP-13',
            'judul' => 'Pengisian Jurnal Harian',
            'periode' => PeriodeLaporan::tanggalPanjang($t),
            'tanggal' => $t->toDateString(),
            'hari' => JamKerja::DAFTAR_HARI[$t->dayOfWeekIso] ?? null,
            'hitung' => $hitung,
            'kelas' => $kelas,
            'kolom' => ['Kelas', 'Jam', 'Mapel', 'Guru', 'Status', 'Keterangan / Materi'],
            'baris' => $baris,
            'ringkasan' => [
                ['label' => 'Kelas', 'nilai' => $hitung['kelas']],
                ['label' => 'Sesi', 'nilai' => $hitung['sesi']],
                ['label' => 'Terisi', 'nilai' => $hitung['sudah']],
                ['label' => 'Belum', 'nilai' => $hitung['belum']],
                ['label' => 'Berhalangan', 'nilai' => $hitung['berhalangan']],
            ],
        ];
    }
}
