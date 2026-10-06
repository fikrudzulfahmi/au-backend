<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** FR-PLM — guru pengampu per (semester, mapel, kelas). BR-03: satu guru per kombinasi. */
final class PlottingMapelService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function simpan(Semester $semester, Pegawai $guru, Mapel $mapel, Kelas $kelas, int $jpPerMinggu, ?User $oleh = null): PlottingMapel
    {
        $this->pastikanGuru($guru);
        $this->pastikanKelasSesuaiSemester($semester, $kelas);
        $this->pastikanJp($jpPerMinggu);

        $bentrok = PlottingMapel::query()
            ->where('semester_id', $semester->id)
            ->where('mapel_id', $mapel->id)
            ->where('kelas_id', $kelas->id)
            ->exists();

        if ($bentrok) {
            throw AturanBisnisException::tolak(
                "Mapel {$mapel->nama} di kelas {$kelas->nama} sudah memiliki guru pengampu pada semester ini (BR-03). Hapus plotting lama lebih dahulu.",
                'mapel_id',
                'BR-03',
            );
        }

        return DB::transaction(function () use ($semester, $guru, $mapel, $kelas, $jpPerMinggu, $oleh): PlottingMapel {
            $plotting = PlottingMapel::create([
                'semester_id' => $semester->id,
                'pegawai_id' => $guru->id,
                'mapel_id' => $mapel->id,
                'kelas_id' => $kelas->id,
                'jp_per_minggu' => $jpPerMinggu,
            ]);

            $this->audit->catat(AuditLogService::AKSI_BUAT, $oleh, $plotting, null, [
                'pegawai_id' => $guru->id,
                'mapel_id' => $mapel->id,
                'kelas_id' => $kelas->id,
            ]);

            return $plotting;
        });
    }

    public function perbarui(PlottingMapel $plotting, int $pegawaiId, int $jpPerMinggu, ?User $oleh = null): PlottingMapel
    {
        $guru = Pegawai::find($pegawaiId);

        if ($guru === null) {
            throw AturanBisnisException::tolak('Guru pengampu tidak ditemukan.', 'pegawai_id');
        }

        $this->pastikanGuru($guru);
        $this->pastikanJp($jpPerMinggu);

        $sudahDijadwalkan = Jadwal::where('plotting_mapel_id', $plotting->id)->count();

        if ($sudahDijadwalkan > $jpPerMinggu) {
            throw AturanBisnisException::tolak(
                "JP per minggu tidak boleh lebih kecil dari jumlah jadwal yang sudah ada ({$sudahDijadwalkan} entri). Hapus sebagian jadwal lebih dahulu.",
                'jp_per_minggu',
                'BR-09',
            );
        }

        $lama = ['pegawai_id' => $plotting->pegawai_id, 'jp_per_minggu' => $plotting->jp_per_minggu];

        $plotting->pegawai_id = $guru->id;
        $plotting->jp_per_minggu = $jpPerMinggu;
        $plotting->save();

        // Guru pada jadwal didenormalisasi (7.3 catatan) — wajib ikut diperbarui agar
        // pengecekan bentrok (BR-06) tetap benar.
        Jadwal::where('plotting_mapel_id', $plotting->id)->update(['pegawai_id' => $guru->id]);

        $this->audit->catat(AuditLogService::AKSI_UBAH, $oleh, $plotting, $lama, [
            'pegawai_id' => $guru->id,
            'jp_per_minggu' => $jpPerMinggu,
        ]);

        return $plotting;
    }

    /** FR-PLM-06 — plotting yang sudah dipakai jadwal tidak boleh dihapus. */
    public function hapus(PlottingMapel $plotting, ?User $oleh = null): void
    {
        $jumlah = Jadwal::where('plotting_mapel_id', $plotting->id)->count();

        if ($jumlah > 0) {
            throw AturanBisnisException::konflik(
                "Plotting ini dipakai oleh {$jumlah} entri jadwal. Hapus jadwalnya lebih dahulu (FR-PLM-06).",
                'DIPAKAI_JADWAL',
            );
        }

        DB::transaction(function () use ($plotting, $oleh): void {
            $lama = [
                'pegawai_id' => $plotting->pegawai_id,
                'mapel_id' => $plotting->mapel_id,
                'kelas_id' => $plotting->kelas_id,
            ];

            $plotting->delete();

            $this->audit->catat(AuditLogService::AKSI_HAPUS, $oleh, $plotting, $lama, null);
        });
    }

    /**
     * FR-PLM-05 — salin plotting dari semester lain.
     * Kombinasi yang sudah ada dilewati agar BR-03 tetap terjaga.
     *
     * @return array{dibuat: int, dilewati: int}
     */
    public function salin(Semester $asal, Semester $tujuan, ?User $oleh = null): array
    {
        if ($asal->id === $tujuan->id) {
            throw AturanBisnisException::tolak('Semester asal dan tujuan tidak boleh sama.', 'semester_id');
        }

        $dibuat = 0;
        $dilewati = 0;

        DB::transaction(function () use ($asal, $tujuan, $oleh, &$dibuat, &$dilewati): void {
            $sumber = PlottingMapel::with('kelas:id,tahun_pelajaran_id')
                ->where('semester_id', $asal->id)
                ->get();

            foreach ($sumber as $baris) {
                $kelasSesuai = $baris->kelas !== null
                    && $baris->kelas->tahun_pelajaran_id === $tujuan->tahun_pelajaran_id;

                if (! $kelasSesuai) {
                    $dilewati++;

                    continue;
                }

                $ada = PlottingMapel::query()
                    ->where('semester_id', $tujuan->id)
                    ->where('mapel_id', $baris->mapel_id)
                    ->where('kelas_id', $baris->kelas_id)
                    ->exists();

                if ($ada) {
                    $dilewati++;

                    continue;
                }

                PlottingMapel::create([
                    'semester_id' => $tujuan->id,
                    'pegawai_id' => $baris->pegawai_id,
                    'mapel_id' => $baris->mapel_id,
                    'kelas_id' => $baris->kelas_id,
                    'jp_per_minggu' => $baris->jp_per_minggu,
                ]);

                $dibuat++;
            }

            $this->audit->catat(AuditLogService::AKSI_SALIN, $oleh, $tujuan, ['semester_asal_id' => $asal->id], [
                'dibuat' => $dibuat,
                'dilewati' => $dilewati,
            ]);
        });

        return ['dibuat' => $dibuat, 'dilewati' => $dilewati];
    }

    /** FR-PLM-03 — hanya pegawai berjenis guru yang dapat menjadi pengampu. */
    private function pastikanGuru(Pegawai $guru): void
    {
        if ($guru->jenis_pegawai !== Pegawai::JENIS_GURU) {
            throw AturanBisnisException::tolak(
                'Hanya pegawai berjenis guru yang dapat menjadi pengampu mapel (FR-PLM-03).',
                'pegawai_id',
            );
        }

        if (! $guru->is_active) {
            throw AturanBisnisException::tolak('Pegawai tersebut tidak aktif.', 'pegawai_id');
        }
    }

    private function pastikanKelasSesuaiSemester(Semester $semester, Kelas $kelas): void
    {
        if ($kelas->tahun_pelajaran_id !== $semester->tahun_pelajaran_id) {
            throw AturanBisnisException::tolak(
                "Kelas {$kelas->nama} bukan bagian dari tahun pelajaran semester ini.",
                'kelas_id',
            );
        }
    }

    private function pastikanJp(int $jp): void
    {
        if ($jp < 1 || $jp > 20) {
            throw AturanBisnisException::tolak('JP per minggu harus antara 1 dan 20.', 'jp_per_minggu');
        }
    }
}
