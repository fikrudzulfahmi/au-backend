<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\Kelas;
use App\Models\MutasiKelas;
use App\Models\PlottingKelas;
use App\Models\Siswa;
use App\Models\TahunPelajaran;
use App\Models\User;
use App\Support\PenjagaHapus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLK — plotting kelas / rombel siswa.
 *
 * Dua hal yang mudah salah dan karena itu ditangani eksplisit di sini:
 *  - Wizard naik kelas bersifat IDEMPOTENT (FR-PLK-03): siswa yang sudah diproses
 *    tidak diproses ulang, sehingga wizard boleh dijalankan bertahap per kelas.
 *  - Seluruh eksekusi TRANSACTIONAL (KP-2.5): seluruh keputusan divalidasi lebih dulu,
 *    dan bila ada satu saja yang tidak lolos, tidak ada perubahan yang disimpan.
 */
final class PlottingKelasService
{
    /** A-10 — kelas XII tidak dapat naik_kelas; hanya lulus/tinggal_kelas/pindah/keluar. */
    private const TINGKAT_BERIKUTNYA = ['X' => 'XI', 'XI' => 'XII'];

    public function __construct(private readonly AuditLogService $audit) {}

    public function tingkatBerikutnya(?string $tingkat): ?string
    {
        return self::TINGKAT_BERIKUTNYA[(string) $tingkat] ?? null;
    }

    /**
     * FR-PLK-01 — menempatkan siswa yang belum terplot ke sebuah kelas (dapat massal).
     *
     * @param  list<int|string>  $siswaIds
     * @return array{dibuat: int, dilewati: list<array{siswa_id: int, alasan: string}>}
     */
    public function plotBaru(Kelas $kelas, array $siswaIds, ?User $oleh = null): array
    {
        $dibuat = 0;
        $dilewati = [];

        DB::transaction(function () use ($kelas, $siswaIds, $oleh, &$dibuat, &$dilewati): void {
            foreach (array_unique(array_map('intval', $siswaIds)) as $siswaId) {
                $siswa = Siswa::find($siswaId);

                if ($siswa === null) {
                    $dilewati[] = ['siswa_id' => $siswaId, 'alasan' => 'Siswa tidak ditemukan.'];

                    continue;
                }

                if ($siswa->status !== 'aktif') {
                    $dilewati[] = [
                        'siswa_id' => $siswaId,
                        'alasan' => "Status siswa \"{$siswa->status}\" — hanya siswa aktif yang dapat diplot (FR-PLK-07).",
                    ];

                    continue;
                }

                $sudahTerplot = PlottingKelas::query()
                    ->where('tahun_pelajaran_id', $kelas->tahun_pelajaran_id)
                    ->where('siswa_id', $siswaId)
                    ->exists();

                if ($sudahTerplot) {
                    $dilewati[] = [
                        'siswa_id' => $siswaId,
                        'alasan' => 'Siswa sudah terplot pada tahun pelajaran ini (BR-04).',
                    ];

                    continue;
                }

                PlottingKelas::create([
                    'tahun_pelajaran_id' => $kelas->tahun_pelajaran_id,
                    'siswa_id' => $siswaId,
                    'kelas_id' => $kelas->id,
                    'status_akhir' => PlottingKelas::BERJALAN,
                ]);

                $dibuat++;
            }

            if ($dibuat > 0) {
                $this->audit->catat(AuditLogService::AKSI_PLOTTING_SISWA, $oleh, $kelas, null, ['jumlah' => $dibuat]);
            }
        });

        return ['dibuat' => $dibuat, 'dilewati' => $dilewati];
    }

    /**
     * FR-PLK-02 butir 3/5 — pratinjau wizard: seluruh siswa kelas asal beserta status akhir
     * bawaan dan saran kelas tujuan, tanpa mengubah apa pun.
     *
     * @param  list<int|string>  $kelasAsalIds
     */
    public function pratinjauWizard(int $tahunAsalId, int $tahunTujuanId, array $kelasAsalIds): array
    {
        $tujuan = TahunPelajaran::find($tahunTujuanId);

        if ($tujuan === null) {
            throw AturanBisnisException::tolak('Tahun pelajaran tujuan tidak ditemukan.', 'tahun_pelajaran_tujuan');
        }

        $kelasTujuan = Kelas::query()
            ->where('tahun_pelajaran_id', $tahunTujuanId)
            ->get()
            ->keyBy('id');

        $daftar = [];
        $ringkasan = ['naik_kelas' => 0, 'tinggal_kelas' => 0, 'lulus' => 0, 'pindah' => 0, 'keluar' => 0, 'sudah_diproses' => 0];

        $kelas = Kelas::query()
            ->with(['jurusan:id,kode,nama'])
            ->where('tahun_pelajaran_id', $tahunAsalId)
            ->whereIn('id', array_map('intval', $kelasAsalIds))
            ->orderBy('nama')
            ->get();

        foreach ($kelas as $k) {
            $plotting = PlottingKelas::query()
                ->with(['siswa:id,nis,nisn,nama,jenis_kelamin,status'])
                ->where('tahun_pelajaran_id', $tahunAsalId)
                ->where('kelas_id', $k->id)
                ->get();

            $siswa = [];
            foreach ($plotting as $p) {
                $bawaan = $this->statusAkhirBawaan((string) $k->tingkat);
                $sudah = $p->sudahDiproses();

                if ($sudah) {
                    $ringkasan['sudah_diproses']++;
                } else {
                    $ringkasan[$bawaan] = ($ringkasan[$bawaan] ?? 0) + 1;
                }

                $siswa[] = [
                    'plotting_id' => $p->id,
                    'siswa_id' => $p->siswa_id,
                    'nis' => $p->siswa?->nis,
                    'nisn' => $p->siswa?->nisn,
                    'nama' => $p->siswa?->nama,
                    'jenis_kelamin' => $p->siswa?->jenis_kelamin,
                    'status_siswa' => $p->siswa?->status,
                    'status_akhir' => $p->status_akhir,
                    'status_akhir_bawaan' => $bawaan,
                    'sudah_diproses' => $sudah,
                    'kelas_tujuan_saran' => $sudah ? null : $this->saranKelasTujuan($k, $bawaan, $kelasTujuan),
                ];
            }

            $daftar[] = [
                'kelas_id' => $k->id,
                'nama' => $k->nama,
                'tingkat' => $k->tingkat,
                'jurusan' => $k->jurusan?->kode,
                'jumlah' => count($siswa),
                'jumlah_l' => collect($siswa)->where('jenis_kelamin', 'L')->count(),
                'jumlah_p' => collect($siswa)->where('jenis_kelamin', 'P')->count(),
                'selesai' => collect($siswa)->every(fn (array $s): bool => $s['sudah_diproses'] === true),
                'siswa' => $siswa,
            ];
        }

        return [
            'tahun_pelajaran_asal' => $tahunAsalId,
            'tahun_pelajaran_tujuan' => ['id' => $tujuan->id, 'nama' => $tujuan->nama],
            'kelas' => $daftar,
            'ringkasan' => $ringkasan,
        ];
    }

    /**
     * FR-PLK-02/03 — eksekusi wizard dalam SATU transaksi.
     * Seluruh keputusan divalidasi sebelum ada penulisan (KP-2.5). Siswa yang sudah
     * diproses atau sudah terplot di tahun tujuan dilewati tanpa menggagalkan proses (KP-2.4).
     *
     * @param  list<array{siswa_id: int|string, status_akhir: string, kelas_tujuan_id?: int|string|null, catatan?: string|null}>  $keputusan
     * @return array{diproses: int, dilewati: list<array{siswa_id: int, alasan: string}>, ringkasan: array<string, int>}
     */
    public function eksekusiWizard(int $tahunAsalId, int $tahunTujuanId, array $keputusan, ?User $oleh = null): array
    {
        if ($tahunAsalId === $tahunTujuanId) {
            throw AturanBisnisException::tolak('Tahun pelajaran asal dan tujuan tidak boleh sama.', 'tahun_pelajaran_tujuan');
        }

        $asal = TahunPelajaran::find($tahunAsalId);
        $tujuan = TahunPelajaran::find($tahunTujuanId);

        if ($asal === null || $tujuan === null) {
            throw AturanBisnisException::tolak('Tahun pelajaran asal atau tujuan tidak ditemukan.');
        }

        $kelasTujuan = Kelas::query()->where('tahun_pelajaran_id', $tujuan->id)->get()->keyBy('id');
        $tahunLulus = $this->tahunKelulusan($asal);

        $galat = [];
        $rencana = [];
        $dilewati = [];

        foreach ($keputusan as $baris) {
            $siswaId = (int) ($baris['siswa_id'] ?? 0);
            $statusAkhir = (string) ($baris['status_akhir'] ?? '');
            $mentah = $baris['kelas_tujuan_id'] ?? null;
            $kelasTujuanId = ($mentah === null || $mentah === '') ? null : (int) $mentah;
            $catatan = isset($baris['catatan']) && $baris['catatan'] !== '' ? (string) $baris['catatan'] : null;

            if (! in_array($statusAkhir, PlottingKelas::SELESAI, true)) {
                $galat[] = "Siswa #{$siswaId}: status akhir \"{$statusAkhir}\" tidak dikenali.";

                continue;
            }

            $plotting = PlottingKelas::query()
                ->where('tahun_pelajaran_id', $asal->id)
                ->where('siswa_id', $siswaId)
                ->first();

            if ($plotting === null) {
                $galat[] = "Siswa #{$siswaId}: tidak terplot pada tahun pelajaran asal.";

                continue;
            }

            // Idempotent (FR-PLK-03): sudah diproses → dilewati, bukan galat.
            if ($plotting->sudahDiproses()) {
                $dilewati[] = ['siswa_id' => $siswaId, 'alasan' => 'Sudah diproses sebelumnya — dilewati.'];

                continue;
            }

            $sudahDiTujuan = PlottingKelas::query()
                ->where('tahun_pelajaran_id', $tujuan->id)
                ->where('siswa_id', $siswaId)
                ->exists();

            if ($sudahDiTujuan) {
                $dilewati[] = ['siswa_id' => $siswaId, 'alasan' => 'Siswa sudah terplot pada tahun pelajaran tujuan — dilewati.'];

                continue;
            }

            $kelasAsal = Kelas::find($plotting->kelas_id);
            $perluTujuan = in_array($statusAkhir, ['naik_kelas', 'tinggal_kelas'], true);

            if ($perluTujuan) {
                if ($kelasTujuanId === null) {
                    $galat[] = "Siswa #{$siswaId}: kelas tujuan wajib dipilih untuk status {$statusAkhir}.";

                    continue;
                }

                $tujuanKelas = $kelasTujuan->get($kelasTujuanId);

                if ($tujuanKelas === null) {
                    $galat[] = "Siswa #{$siswaId}: kelas tujuan harus berada pada tahun pelajaran tujuan.";

                    continue;
                }

                if ($statusAkhir === 'naik_kelas') {
                    $berikut = $this->tingkatBerikutnya($kelasAsal?->tingkat);

                    if ($berikut === null) {
                        $galat[] = "Siswa #{$siswaId}: kelas XII tidak dapat naik kelas (A-10).";

                        continue;
                    }

                    if ($tujuanKelas->tingkat !== $berikut) {
                        $galat[] = "Siswa #{$siswaId}: kelas tujuan harus bertingkat {$berikut} (tingkat +1).";

                        continue;
                    }
                }

                if ($statusAkhir === 'tinggal_kelas' && $tujuanKelas->tingkat !== $kelasAsal?->tingkat) {
                    $galat[] = "Siswa #{$siswaId}: tinggal kelas harus pada tingkat yang sama dengan kelas asal.";

                    continue;
                }
            } elseif ($kelasTujuanId !== null) {
                $galat[] = "Siswa #{$siswaId}: status {$statusAkhir} tidak memakai kelas tujuan.";

                continue;
            }

            $rencana[] = [
                'plotting' => $plotting,
                'siswa_id' => $siswaId,
                'status_akhir' => $statusAkhir,
                'kelas_tujuan_id' => $kelasTujuanId,
                'catatan' => $catatan,
            ];
        }

        if ($galat !== []) {
            throw AturanBisnisException::kumpulan(
                'Proses naik kelas dibatalkan karena ada '.count($galat).' data yang tidak lolos validasi. Tidak ada perubahan yang disimpan.',
                $galat,
            );
        }

        if ($rencana === []) {
            return ['diproses' => 0, 'dilewati' => $dilewati, 'ringkasan' => []];
        }

        $ringkasan = [];

        DB::transaction(function () use ($rencana, $asal, $tujuan, $tahunLulus, $oleh, &$ringkasan): void {
            foreach ($rencana as $butir) {
                /** @var PlottingKelas $plotting */
                $plotting = $butir['plotting'];
                $status = $butir['status_akhir'];

                $plotting->status_akhir = $status;
                $plotting->catatan = $butir['catatan'] ?? $plotting->catatan;
                $plotting->save();

                if (in_array($status, ['naik_kelas', 'tinggal_kelas'], true)) {
                    PlottingKelas::create([
                        'tahun_pelajaran_id' => $tujuan->id,
                        'siswa_id' => $butir['siswa_id'],
                        'kelas_id' => $butir['kelas_tujuan_id'],
                        'status_akhir' => PlottingKelas::BERJALAN,
                        'plotting_sebelumnya_id' => $plotting->id,
                    ]);
                }

                if (in_array($status, PlottingKelas::STATUS_SISWA, true)) {
                    $siswa = Siswa::find($butir['siswa_id']);

                    if ($siswa !== null) {
                        $siswa->status = $status;
                        $siswa->tanggal_status = now()->toDateString();
                        $siswa->tahun_lulus = $status === 'lulus' ? $tahunLulus : null;
                        $siswa->save();
                    }
                }

                $ringkasan[$status] = ($ringkasan[$status] ?? 0) + 1;
            }

            $this->audit->catat(AuditLogService::AKSI_NAIK_KELAS, $oleh, $tujuan, [
                'tahun_pelajaran_asal_id' => $asal->id,
            ], [
                'tahun_pelajaran_tujuan_id' => $tujuan->id,
                'ringkasan' => $ringkasan,
                'jumlah_diproses' => count($rencana),
            ]);
        });

        return ['diproses' => count($rencana), 'dilewati' => $dilewati, 'ringkasan' => $ringkasan];
    }

    /** FR-PLK-05 — mutasi siswa antar kelas dalam tahun berjalan; alasan wajib. */
    public function mutasi(PlottingKelas $plotting, Kelas $tujuan, string $tanggal, string $alasan, ?User $oleh = null): MutasiKelas
    {
        if (trim($alasan) === '') {
            throw AturanBisnisException::tolak('Alasan mutasi wajib diisi.', 'alasan');
        }

        if ($plotting->kelas_id === $tujuan->id) {
            throw AturanBisnisException::tolak('Kelas tujuan sama dengan kelas saat ini.', 'kelas_tujuan_id');
        }

        if ($plotting->tahun_pelajaran_id !== $tujuan->tahun_pelajaran_id) {
            throw AturanBisnisException::tolak('Mutasi hanya berlaku di dalam satu tahun pelajaran.', 'kelas_tujuan_id');
        }

        return DB::transaction(function () use ($plotting, $tujuan, $tanggal, $alasan, $oleh): MutasiKelas {
            $asal = $plotting->kelas_id;

            // Data presensi yang sudah tercatat tetap melekat pada jurnal/kelas lamanya
            // (FR-PLK-05) — karena itu kelas pada jadwal/jurnal tidak ikut diubah di sini.
            $plotting->kelas_id = $tujuan->id;
            $plotting->save();

            $mutasi = MutasiKelas::create([
                'plotting_kelas_id' => $plotting->id,
                'kelas_asal_id' => $asal,
                'kelas_tujuan_id' => $tujuan->id,
                'tanggal' => $tanggal,
                'alasan' => $alasan,
                'dibuat_oleh' => $oleh?->getKey(),
            ]);

            $this->audit->catat(AuditLogService::AKSI_MUTASI_KELAS, $oleh, $mutasi, [
                'kelas_asal_id' => $asal,
            ], [
                'kelas_tujuan_id' => $tujuan->id,
                'alasan' => $alasan,
            ]);

            return $mutasi;
        });
    }

    /**
     * FR-PLK-06 — membatalkan hasil naik kelas, selama tahun pelajaran tujuan belum
     * memiliki presensi/jurnal siswa tersebut. Karena itu penjagaannya memakai
     * PenjagaHapus: begitu tabel rujukan fase berikutnya dibuat, pembatalan otomatis
     * ikut tertutup tanpa mengubah kode ini.
     *
     * Menerima baris plotting tujuan (punya plotting_sebelumnya_id) maupun baris asal
     * yang sudah berstatus akhir (mis. lulus).
     *
     * @return array{siswa_id: int, status_dikembalikan: string|null}
     */
    public function batalkan(PlottingKelas $plotting, ?User $oleh = null): array
    {
        $terhalang = PenjagaHapus::periksa($plotting->siswa_id, [
            ['presensi_siswa', 'siswa_id', 'presensi siswa'],
        ]);

        if ($terhalang !== null) {
            throw AturanBisnisException::konflik(
                'Hasil naik kelas tidak dapat dibatalkan karena tahun pelajaran tujuan sudah memiliki '.$terhalang.'.',
                'SUDAH_ADA_PRESENSI',
            );
        }

        return DB::transaction(function () use ($plotting, $oleh): array {
            $statusDikembalikan = null;

            $asal = $plotting->plottingSebelumnya;
            $statusSebelumnya = $plotting->status_akhir;
            $daftar = $asal !== null ? [$plotting, $asal] : [$plotting];

            foreach ($daftar as $baris) {
                $baris->status_akhir = PlottingKelas::BERJALAN;
                $baris->catatan = null;
                $baris->save();
            }

            $siswa = Siswa::find($plotting->siswa_id);

            if ($siswa !== null && in_array($siswa->status, PlottingKelas::STATUS_SISWA, true)) {
                $statusDikembalikan = $siswa->status;
                $siswa->status = 'aktif';
                $siswa->tanggal_status = null;
                $siswa->tahun_lulus = null;
                $siswa->save();
            }

            // Baris plotting tujuan dihapus agar siswa dapat diproses ulang (FR-PLK-06).
            // Bila yang dibatalkan adalah baris asal (mis. lulus), baris itu dipertahankan.
            if ($asal !== null) {
                $plotting->delete();
            }

            $this->audit->catat(AuditLogService::AKSI_BATAL_NAIK_KELAS, $oleh, $plotting, [
                'status_akhir' => $statusSebelumnya,
            ], [
                'siswa_id' => (int) $plotting->siswa_id,
                'status_akhir_baru' => PlottingKelas::BERJALAN,
            ]);

            return ['siswa_id' => (int) $plotting->siswa_id, 'status_dikembalikan' => $statusDikembalikan];
        });
    }

    /** Status akhir bawaan menurut tingkat kelas asal (FR-PLK-02 butir 3). */
    public function statusAkhirBawaan(string $tingkat): string
    {
        return $tingkat === 'XII' ? 'lulus' : 'naik_kelas';
    }

    /**
     * Saran kelas tujuan (FR-PLK-02 butir 5): tingkat +1 jurusan sama untuk naik_kelas,
     * tingkat sama untuk tinggal_kelas.
     *
     * @param  Collection<int, Kelas>  $kelasTujuan
     */
    private function saranKelasTujuan(Kelas $kelasAsal, string $statusAkhir, $kelasTujuan): ?int
    {
        if (! in_array($statusAkhir, ['naik_kelas', 'tinggal_kelas'], true)) {
            return null;
        }

        $tingkat = $statusAkhir === 'naik_kelas'
            ? $this->tingkatBerikutnya($kelasAsal->tingkat)
            : $kelasAsal->tingkat;

        if ($tingkat === null) {
            return null;
        }

        $kandidat = $kelasTujuan
            ->where('tingkat', $tingkat)
            ->where('jurusan_id', $kelasAsal->jurusan_id)
            ->where('is_active', true)
            ->sortBy('nama')
            ->first();

        return $kandidat?->id;
    }

    /** Tahun kelulusan diambil dari akhir tahun pelajaran asal (mis. 2026/2027 → 2027). */
    private function tahunKelulusan(TahunPelajaran $asal): ?int
    {
        if ($asal->tanggal_selesai !== null) {
            return (int) $asal->tanggal_selesai->format('Y');
        }

        $bagian = explode('/', (string) $asal->nama);

        return isset($bagian[1]) && is_numeric($bagian[1]) ? (int) $bagian[1] : null;
    }
}
