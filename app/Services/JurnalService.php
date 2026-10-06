<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\Jadwal;
use App\Models\Jurnal;
use App\Models\JurnalFoto;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PlottingKelas;
use App\Models\PresensiPegawai;
use App\Models\PresensiSiswa;
use App\Models\Semester;
use App\Models\Siswa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 5.13 / 7.5 — Jurnal pembelajaran + presensi siswa (FR-JRN-01..10).
 *
 * Aturan yang ditegakkan di sini:
 *  - BR-19  jurnal tanggal T hanya bila ada presensi masuk T yang dihitung.
 *  - BR-20  jurnal boleh diedit kapan pun oleh pemiliknya; setiap edit dicatat.
 *  - BR-21  satu sesi satu jurnal (ditegakkan juga oleh indeks unik database).
 *  - BR-22  daftar siswa = siswa aktif yang terplot di kelas itu pada tahun pelajaran jurnal.
 *  - BR-23  status presensi siswa hanya H/S/I/A.
 *  - BR-26  sesi pada hari izin disetujui tampil "Berhalangan" dan tidak dapat diisi.
 *  - A-12   maksimal 3 foto per jurnal, dikompres seperti foto presensi.
 */
class JurnalService
{
    /** Status sesi pada daftar jadwal beranda (FR-JRN-01). */
    public const BELUM = 'belum';

    public const SUDAH = 'sudah';

    public const BERHALANGAN = 'berhalangan';

    public function __construct(
        private readonly BerkasService $berkas,
        private readonly AuditLogService $audit,
        private readonly PengajuanService $pengajuan,
    ) {}

    // =====================================================================
    // Sesi dari jadwal
    // =====================================================================

    /**
     * FR-JRN-01 — sesi terbentuk dari jadwal: entri berurutan untuk plotting mapel
     * dan hari yang sama digabung menjadi satu sesi (mis. jam ke-1..3 = satu jurnal).
     *
     * Penggabungan hanya terjadi bila jam ke benar-benar berurutan (`+1`) DAN plotting
     * mapelnya sama, sehingga dua mapel berbeda pada jam 1 dan 3 tidak ikut tergabung.
     */
    public function sesi(Pegawai $guru, Semester $semester, string $tanggal): array
    {
        $hari = CarbonImmutable::parse($tanggal)->isoWeekday();

        $jadwal = Jadwal::query()
            ->with(['slotJam', 'plottingMapel.mapel:id,kode,nama', 'plottingMapel.kelas:id,nama'])
            ->where('semester_id', $semester->id)
            ->where('pegawai_id', $guru->id)
            ->where('hari', $hari)
            ->get();

        return $this->tandaiStatus($guru, $semester, $tanggal, $this->gabungSesi($jadwal));
    }

    /**
     * Menggabungkan entri jadwal menjadi daftar sesi (FR-JRN-01).
     *
     * Dipisah menjadi publik karena laporan kepatuhan jurnal (FR-LAP-08) perlu
     * membentuk sesi yang sama dari jadwal yang sudah diambilnya sekaligus —
     * memakai ulang aturan penggabungan ini menjamin keduanya tidak pernah berbeda.
     *
     * @param  Collection<int, Jadwal>  $jadwal
     * @return list<array<string, mixed>>
     */
    public function gabungSesi(Collection $jadwal): array
    {
        $urut = $jadwal
            ->filter(fn (Jadwal $j): bool => $j->slotJam?->jam_ke !== null)
            ->sortBy(fn (Jadwal $j): int => (int) $j->slotJam->urutan)
            ->values();

        $sesi = [];

        foreach ($urut as $j) {
            $jamKe = (int) $j->slotJam->jam_ke;
            $akhir = count($sesi) - 1;

            $lanjutan = $akhir >= 0
                && $sesi[$akhir]['plotting_mapel_id'] === (int) $j->plotting_mapel_id
                && $jamKe === $sesi[$akhir]['jam_ke_selesai'] + 1;

            if ($lanjutan) {
                $sesi[$akhir]['jam_ke_selesai'] = $jamKe;
                $sesi[$akhir]['jam_selesai'] = $j->slotJam->jamSelesaiPendek();

                continue;
            }

            $sesi[] = [
                'plotting_mapel_id' => (int) $j->plotting_mapel_id,
                'kelas_id' => (int) $j->kelas_id,
                'kelas' => $j->plottingMapel?->kelas?->nama,
                'mapel' => $j->plottingMapel?->mapel?->nama,
                'kode_mapel' => $j->plottingMapel?->mapel?->kode,
                'jam_ke_mulai' => $jamKe,
                'jam_ke_selesai' => $jamKe,
                'jam_mulai' => $j->slotJam->jamMulaiPendek(),
                'jam_selesai' => $j->slotJam->jamSelesaiPendek(),
            ];
        }

        return $sesi;
    }

    /**
     * Mengambil jadwal beberapa guru sekaligus, dikelompokkan [pegawai_id][hari].
     *
     * Dipakai laporan kepatuhan jurnal (FR-LAP-08) agar tidak melakukan query per
     * (guru × tanggal); penggabungan sesinya tetap lewat `gabungSesi()` sehingga
     * hasilnya identik dengan daftar sesi pada halaman isi jurnal.
     *
     * @param  list<int>  $pegawaiIds
     * @return Collection<int, Collection<int, Collection<int, Jadwal>>>
     */
    public function sesiPersiapanJadwal(Semester $semester, array $pegawaiIds): Collection
    {
        return Jadwal::query()
            ->with(['slotJam', 'plottingMapel.mapel:id,kode,nama', 'plottingMapel.kelas:id,nama'])
            ->where('semester_id', $semester->id)
            ->whereIn('pegawai_id', $pegawaiIds)
            ->get()
            ->groupBy('pegawai_id')
            ->map(fn (Collection $perGuru): Collection => $perGuru->groupBy('hari'));
    }

    /**
     * Melengkapi tiap sesi dengan status dan alasan boleh/tidaknya diisi.
     *
     * @param  list<array<string, mixed>>  $sesi
     * @return list<array<string, mixed>>
     */
    private function tandaiStatus(Pegawai $guru, Semester $semester, string $tanggal, array $sesi): array
    {
        if ($sesi === []) {
            return [];
        }

        $sudah = Jurnal::query()
            ->where('semester_id', $semester->id)
            ->where('pegawai_id', $guru->id)
            ->whereDate('tanggal', $tanggal)
            ->get()
            ->keyBy(fn (Jurnal $j): string => $j->plotting_mapel_id.':'.$j->jam_ke_mulai);

        $pengajuan = $this->berhalangan($guru, $tanggal);
        $adaPresensi = $this->presensiMasukMemenuhi($guru, $tanggal);

        foreach ($sesi as $i => $s) {
            $jurnal = $sudah->get($s['plotting_mapel_id'].':'.$s['jam_ke_mulai']);

            if ($pengajuan !== null) {
                $status = self::BERHALANGAN;
                $alasan = 'Berhalangan — '.$pengajuan->labelJenis().' disetujui.';
            } elseif ($jurnal !== null) {
                $status = self::SUDAH;
                $alasan = null;
            } else {
                $status = self::BELUM;
                $alasan = $adaPresensi ? null : 'Lakukan presensi masuk terlebih dahulu';
            }

            $sesi[$i]['jurnal_id'] = $jurnal?->id;
            $sesi[$i]['label_jam'] = $jurnal?->labelJamKe() ?? $this->labelJamKe($s['jam_ke_mulai'], $s['jam_ke_selesai']);
            $sesi[$i]['status'] = $status;
            $sesi[$i]['alasan'] = $alasan;
            // KP-4.6 / BR-19 — "Berhalangan" tidak dapat diisi, dan belum presensi juga tidak.
            $sesi[$i]['boleh_isi'] = $status === self::BELUM && $alasan === null;
        }

        return $sesi;
    }

    public function labelJamKe(int $mulai, int $selesai): string
    {
        return $mulai === $selesai ? 'Jam ke-'.$mulai : 'Jam ke-'.$mulai.'-'.$selesai;
    }

    // =====================================================================
    // Gerbang aturan
    // =====================================================================

    /** KP-4.6 / FR-IZN-07 / BR-26 — pengajuan disetujui yang membebaskan presensi & jurnal. */
    public function berhalangan(Pegawai $guru, string $tanggal): ?PengajuanIzin
    {
        return $this->pengajuan->berhalanganPada($guru, $tanggal);
    }

    /**
     * BR-19 / FR-JRN-04 — presensi masuk tanggal T harus berstatus validasi
     * `valid`, `disetujui`, atau `menunggu`. A-01: `menunggu` tetap membuka akses,
     * `ditolak` atau belum presensi memblokir.
     */
    public function presensiMasukMemenuhi(Pegawai $guru, string $tanggal): bool
    {
        return PresensiPegawai::query()
            ->where('pegawai_id', $guru->id)
            ->whereDate('tanggal', $tanggal)
            ->whereNotNull('masuk_waktu')
            ->whereIn('masuk_validasi', PresensiPegawai::VALIDASI_DIHITUNG)
            ->exists();
    }

    /**
     * BR-22 — siswa `aktif` yang terplot di kelas itu pada tahun pelajaran jurnal.
     *
     * @return Collection<int, PlottingKelas>
     */
    public function siswaKelas(Semester $semester, int $kelasId): Collection
    {
        return PlottingKelas::query()
            ->untukTahun((int) $semester->tahun_pelajaran_id)
            ->where('kelas_id', $kelasId)
            ->whereHas('siswa', fn ($q) => $q->where('status', Siswa::STATUS_AKTIF))
            ->with('siswa:id,nis,nama,jenis_kelamin')
            ->get()
            ->sortBy(fn (PlottingKelas $p): string => (string) $p->siswa?->nama)
            ->values();
    }

    // =====================================================================
    // Menyimpan
    // =====================================================================

    /**
     * Membuat jurnal baru untuk satu sesi.
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $foto
     */
    public function buat(User $user, Pegawai $guru, array $data, array $foto = []): Jurnal
    {
        $semester = Semester::query()->findOrFail((int) $data['semester_id']);
        $tanggal = (string) $data['tanggal'];

        $sesi = $this->sesiUntuk(
            $guru,
            $semester,
            $tanggal,
            (int) $data['plotting_mapel_id'],
            (int) $data['jam_ke_mulai'],
        );

        try {
            $jurnal = DB::transaction(function () use ($user, $guru, $semester, $tanggal, $data, $foto, $sesi): Jurnal {
                $jurnal = Jurnal::create([
                    'semester_id' => $semester->id,
                    'plotting_mapel_id' => $sesi['plotting_mapel_id'],
                    'pegawai_id' => $guru->id,
                    'kelas_id' => $sesi['kelas_id'],
                    'tanggal' => $tanggal,
                    'jam_ke_mulai' => $sesi['jam_ke_mulai'],
                    // Jam selesai diambil dari jadwal (server otoritatif), bukan dari klien.
                    'jam_ke_selesai' => $sesi['jam_ke_selesai'],
                    'materi' => $data['materi'],
                    'kegiatan' => $data['kegiatan'],
                    'catatan' => $data['catatan'] ?? null,
                    'dibuat_oleh' => $user->id,
                ]);

                $this->simpanPresensiSiswa($jurnal, $semester, $data['presensi'] ?? []);
                $this->simpanFoto($jurnal, $foto);

                return $jurnal;
            });
        } catch (QueryException $e) {
            // BR-21 — balapan dua permintaan pada sesi yang sama.
            if ($this->galatDuplikat($e)) {
                throw AturanBisnisException::konflik(
                    'Jurnal untuk sesi ini sudah ada. Muat ulang halaman untuk melihatnya.',
                    'JURNAL_SUDAH_ADA',
                );
            }

            throw $e;
        }

        $this->audit->catat(AuditLogService::AKSI_ISI_JURNAL, $user, $jurnal, null, [
            'kelas' => $sesi['kelas'],
            'mapel' => $sesi['mapel'],
            'tanggal' => $tanggal,
            'jam_ke' => $jurnal->labelJamKe(),
        ]);

        return $jurnal->fresh(['foto', 'presensiSiswa.siswa']) ?? $jurnal;
    }

    /**
     * Memvalidasi bahwa sesi yang diminta benar ada pada jadwal guru, sekaligus
     * menegakkan KP-4.6 (berhalangan) dan BR-19 (presensi masuk).
     *
     * @return array<string, mixed> sesi yang cocok
     */
    private function sesiUntuk(Pegawai $guru, Semester $semester, string $tanggal, int $plottingMapelId, int $jamKeMulai): array
    {
        // KP-4.6 — sesi pada hari izin disetujui tampil "Berhalangan" dan tidak dapat diisi.
        $pengajuan = $this->berhalangan($guru, $tanggal);

        if ($pengajuan !== null) {
            throw AturanBisnisException::tolak(
                'Anda berhalangan pada tanggal ini ('.$pengajuan->labelJenis().' disetujui), jurnal tidak dapat diisi.',
                'tanggal',
                'BERHALANGAN',
            );
        }

        // BR-19 / FR-JRN-04
        if (! $this->presensiMasukMemenuhi($guru, $tanggal)) {
            throw AturanBisnisException::tolak(
                'Lakukan presensi masuk terlebih dahulu.',
                'tanggal',
                'BELUM_PRESENSI_MASUK',
            );
        }

        // FR-JRN-06 — hanya sesi pada jadwal guru sendiri.
        $sesi = collect($this->sesi($guru, $semester, $tanggal))
            ->first(fn (array $s): bool => $s['plotting_mapel_id'] === $plottingMapelId && $s['jam_ke_mulai'] === $jamKeMulai);

        if ($sesi === null) {
            throw AturanBisnisException::tolak(
                'Sesi ini tidak ada pada jadwal mengajar Anda.',
                'plotting_mapel_id',
                'SESI_BUKAN_MILIK_ANDA',
            );
        }

        if ($sesi['status'] === self::SUDAH) {
            throw AturanBisnisException::konflik(
                'Jurnal untuk sesi ini sudah ada.',
                'JURNAL_SUDAH_ADA',
            );
        }

        return $sesi;
    }

    /** BR-20 / FR-JRN-05 — edit tanpa batas waktu oleh pemilik, selalu dicatat. */
    public function perbarui(User $user, Jurnal $jurnal, array $data, ?array $fotoBaru = null): Jurnal
    {
        $lama = [
            'materi' => $jurnal->materi,
            'kegiatan' => $jurnal->kegiatan,
            'catatan' => $jurnal->catatan,
        ];

        DB::transaction(function () use ($user, $jurnal, $data, $fotoBaru): void {
            $jurnal->fill([
                'materi' => $data['materi'],
                'kegiatan' => $data['kegiatan'],
                'catatan' => $data['catatan'] ?? null,
                'diubah_oleh' => $user->id,
            ])->save();

            if (array_key_exists('presensi', $data)) {
                $this->simpanPresensiSiswa($jurnal, $jurnal->semester, $data['presensi']);
            }

            if ($fotoBaru !== null) {
                $this->simpanFoto($jurnal, $fotoBaru);
            }
        });

        // Ringkas nilai lama-baru agar jejaknya berguna tanpa membanjiri audit_log.
        $this->audit->catat(AuditLogService::AKSI_UBAH_JURNAL, $user, $jurnal, $lama, [
            'materi' => $jurnal->materi,
            'kegiatan' => $jurnal->kegiatan,
            'catatan' => $jurnal->catatan,
        ]);

        return $jurnal->fresh(['foto', 'presensiSiswa.siswa']) ?? $jurnal;
    }

    /**
     * Menyimpan presensi siswa sebuah jurnal (FR-JRN-03, BR-22, BR-23).
     *
     * Hanya siswa yang benar-benar terplot di kelas itu yang diterima; siswa di luar
     * daftar ditolak, bukan diabaikan diam-diam.
     *
     * @param  array<int|string, array{status?: string, keterangan?: string|null}>  $presensi
     */
    private function simpanPresensiSiswa(Jurnal $jurnal, Semester $semester, array $presensi): void
    {
        $diizinkan = $this->siswaKelas($semester, (int) $jurnal->kelas_id)
            ->pluck('siswa_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $baris = [];
        $sekarang = now();

        foreach ($presensi as $id => $isi) {
            $siswaId = (int) (is_array($isi) && isset($isi['siswa_id']) ? $isi['siswa_id'] : $id);

            // BR-22 — siswa di luar kelas/tidak aktif pada tahun pelajaran ini ditolak.
            if (! in_array($siswaId, $diizinkan, true)) {
                throw AturanBisnisException::tolak(
                    'Ada siswa yang bukan anggota kelas ini pada tahun pelajaran tersebut.',
                    'presensi',
                    'SISWA_DI_LUAR_KELAS',
                );
            }

            $status = (string) ($isi['status'] ?? PresensiSiswa::HADIR);

            // BR-23 — status hanya H/S/I/A.
            if (! in_array($status, PresensiSiswa::STATUS, true)) {
                throw AturanBisnisException::tolak(
                    'Status presensi siswa hanya boleh H, S, I, atau A.',
                    'presensi',
                    'STATUS_TIDAK_SAH',
                );
            }

            $baris[] = [
                'jurnal_id' => $jurnal->id,
                'siswa_id' => $siswaId,
                'status' => $status,
                'keterangan' => $isi['keterangan'] ?? null,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ];
        }

        if ($baris === []) {
            return;
        }

        // Ganti utuh agar edit tidak meninggalkan baris lama yang sudah tidak dikirim.
        PresensiSiswa::query()->where('jurnal_id', $jurnal->id)->delete();
        PresensiSiswa::query()->insert($baris);
    }

    /**
     * A-12 — maksimal 3 foto per jurnal, dikompres seperti foto presensi.
     *
     * @param  list<UploadedFile>  $foto
     */
    private function simpanFoto(Jurnal $jurnal, array $foto): void
    {
        $foto = array_values(array_filter($foto, fn ($f): bool => $f instanceof UploadedFile));

        if ($foto === []) {
            return;
        }

        $terpakai = JurnalFoto::query()->where('jurnal_id', $jurnal->id)->count();

        if ($terpakai + count($foto) > JurnalFoto::MAKS_PER_JURNAL) {
            throw AturanBisnisException::tolak(
                'Foto kegiatan maksimal '.JurnalFoto::MAKS_PER_JURNAL.' buah.',
                'foto',
                'FOTO_MELEBIHI_BATAS',
            );
        }

        foreach ($foto as $berkas) {
            $path = $this->berkas->simpanGambarTerkompres($berkas, 'jurnal/'.$jurnal->id);

            JurnalFoto::create([
                'jurnal_id' => $jurnal->id,
                'foto_path' => $path,
                'urutan' => ++$terpakai,
            ]);
        }
    }

    // =====================================================================
    // Membaca
    // =====================================================================

    /** FR-JRN-09 — riwayat jurnal milik sendiri dengan filter periode/kelas/mapel. */
    public function riwayat(Pegawai $guru, array $filter, int $perHalaman = 20): LengthAwarePaginator
    {
        return Jurnal::query()
            ->with(['kelas:id,nama', 'plottingMapel.mapel:id,kode,nama', 'foto'])
            ->withCount([
                'presensiSiswa as jumlah_hadir' => fn ($q) => $q->where('status', PresensiSiswa::HADIR),
                'presensiSiswa as jumlah_sakit' => fn ($q) => $q->where('status', PresensiSiswa::SAKIT),
                'presensiSiswa as jumlah_izin' => fn ($q) => $q->where('status', PresensiSiswa::IZIN),
                'presensiSiswa as jumlah_alpa' => fn ($q) => $q->where('status', PresensiSiswa::ALPA),
            ])
            ->untukPegawai((int) $guru->id)
            ->rentangTanggal($filter['dari'] ?? null, $filter['sampai'] ?? null)
            ->when(! empty($filter['kelas_id']), fn ($q) => $q->where('kelas_id', (int) $filter['kelas_id']))
            ->when(! empty($filter['mapel_id']), fn ($q) => $q->whereHas(
                'plottingMapel',
                fn ($m) => $m->where('mapel_id', (int) $filter['mapel_id'])
            ))
            ->orderByDesc('tanggal')
            ->orderBy('jam_ke_mulai')
            ->paginate($perHalaman);
    }

    /**
     * FR-JRN-10 — rekap presensi siswa satu kelas pada rentang periode:
     * per siswa jumlah H/S/I/A dan persentasenya.
     *
     * @return array{ringkasan: array<string, int>, siswa: list<array<string, mixed>>, sesi: int}
     */
    public function rekapPresensiSiswa(Semester $semester, int $kelasId, ?string $dari, ?string $sampai, ?int $mapelId = null): array
    {
        $jurnal = Jurnal::query()
            ->where('semester_id', $semester->id)
            ->untukKelas($kelasId)
            ->rentangTanggal($dari, $sampai)
            // FR-LAP-06 — rekap dapat difilter per mata pelajaran.
            ->when($mapelId !== null, fn ($q) => $q->whereHas(
                'plottingMapel',
                fn ($m) => $m->where('mapel_id', $mapelId)
            ))
            ->orderBy('tanggal')
            ->orderBy('jam_ke_mulai')
            ->get(['id', 'tanggal', 'jam_ke_mulai', 'jam_ke_selesai', 'plotting_mapel_id']);

        $idJurnal = $jurnal->pluck('id')->all();

        $siswa = $this->siswaKelas($semester, $kelasId);
        $idSiswa = $siswa->pluck('siswa_id')->map(fn ($v): int => (int) $v)->all();

        // Satu query untuk semua pasangan, lalu dihitung di memori — menghindari
        // N+1 bila kelasnya besar.
        $hitungan = PresensiSiswa::query()
            ->whereIn('jurnal_id', $idJurnal)
            ->whereIn('siswa_id', $idSiswa)
            ->get(['siswa_id', 'status'])
            ->groupBy('siswa_id');

        $ringkasan = [
            PresensiSiswa::HADIR => 0,
            PresensiSiswa::SAKIT => 0,
            PresensiSiswa::IZIN => 0,
            PresensiSiswa::ALPA => 0,
        ];

        $baris = [];

        foreach ($siswa as $plot) {
            $siswaId = (int) $plot->siswa_id;
            $milik = $hitungan->get($siswaId) ?? collect();

            $jumlah = [
                PresensiSiswa::HADIR => $milik->where('status', PresensiSiswa::HADIR)->count(),
                PresensiSiswa::SAKIT => $milik->where('status', PresensiSiswa::SAKIT)->count(),
                PresensiSiswa::IZIN => $milik->where('status', PresensiSiswa::IZIN)->count(),
                PresensiSiswa::ALPA => $milik->where('status', PresensiSiswa::ALPA)->count(),
            ];

            foreach ($jumlah as $kunci => $nilai) {
                $ringkasan[$kunci] += $nilai;
            }

            $total = array_sum($jumlah);

            $baris[] = [
                'siswa_id' => $siswaId,
                'nis' => $plot->siswa?->nis,
                'nama' => $plot->siswa?->nama,
                'hadir' => $jumlah[PresensiSiswa::HADIR],
                'sakit' => $jumlah[PresensiSiswa::SAKIT],
                'izin' => $jumlah[PresensiSiswa::IZIN],
                'alpa' => $jumlah[PresensiSiswa::ALPA],
                'total' => $total,
                'persen_hadir' => $total > 0 ? round(100 * $jumlah[PresensiSiswa::HADIR] / $total, 1) : null,
            ];
        }

        return [
            'ringkasan' => $ringkasan,
            'siswa' => $baris,
            'sesi' => count($idJurnal),
        ];
    }

    /** MySQL 1062 — pelanggaran indeks unik. */
    private function galatDuplikat(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
