<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\Jadwal;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\PolaJam;
use App\Models\PolaJamHari;
use App\Models\Semester;
use App\Models\SlotJam;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * FR-JDW — jadwal pelajaran.
 *
 * Bentrok ditegakkan BERLAPIS: pemeriksaan di sini menghasilkan pesan yang menyebut
 * kelas/guru yang bentrok, sedangkan indeks unik di tabel `jadwal` (BR-06, BR-07)
 * menjadi jaring terakhir bila ada permintaan bersamaan. Pelanggaran indeks tetap
 * diterjemahkan menjadi pesan yang dapat dibaca, bukan 500.
 */
final class JadwalService
{
    public function __construct(
        private readonly JamPelajaranService $jam,
        private readonly AuditLogService $audit,
    ) {}

    public function simpan(Semester $semester, int $hari, int $slotJamId, int $plottingMapelId, ?User $oleh = null): Jadwal
    {
        if ($hari < 1 || $hari > 7) {
            throw AturanBisnisException::tolak('Hari tidak dikenali (1=Senin … 7=Minggu).', 'hari');
        }

        // BR-08 — hanya slot pelajaran dari pola jam hari terkait yang dapat dijadwalkan.
        $pola = $this->jam->polaUntukHari($semester, $hari);

        if ($pola === null) {
            throw AturanBisnisException::tolak(
                'Hari '.$this->namaHari($hari).' belum memiliki pola jam pada semester ini, sehingga belum dapat dijadwalkan.',
                'hari',
                'BR-08',
            );
        }

        $slot = SlotJam::find($slotJamId);

        if ($slot === null) {
            throw AturanBisnisException::tolak('Slot jam tidak ditemukan.', 'slot_jam_id');
        }

        if ($slot->pola_jam_id !== $pola->id) {
            throw AturanBisnisException::tolak(
                'Slot tersebut bukan bagian pola jam untuk hari '.$this->namaHari($hari).' (BR-08).',
                'slot_jam_id',
                'BR-08',
            );
        }

        if (! $slot->bolehDijadwalkan()) {
            throw AturanBisnisException::tolak(
                "Slot \"{$slot->label}\" bertipe {$slot->tipe} — hanya slot bertipe pelajaran yang dapat dijadwalkan (BR-08).",
                'slot_jam_id',
                'BR-08',
            );
        }

        $plotting = PlottingMapel::with(['pegawai:id,nama', 'kelas:id,nama', 'mapel:id,kode,nama'])
            ->find($plottingMapelId);

        if ($plotting === null) {
            throw AturanBisnisException::tolak('Plotting mapel tidak ditemukan.', 'plotting_mapel_id');
        }

        if ($plotting->semester_id !== $semester->id) {
            throw AturanBisnisException::tolak(
                'Plotting mapel tersebut bukan bagian dari semester ini.',
                'plotting_mapel_id',
            );
        }

        // BR-06 — guru tidak boleh mengajar dua kelas pada hari+slot yang sama.
        $bentrokGuru = Jadwal::with(['kelas:id,nama', 'plottingMapel.mapel:id,nama'])
            ->where('semester_id', $semester->id)
            ->where('hari', $hari)
            ->where('slot_jam_id', $slot->id)
            ->where('pegawai_id', $plotting->pegawai_id)
            ->first();

        if ($bentrokGuru !== null) {
            $kelasLain = $bentrokGuru->kelas?->nama ?? 'kelas lain';
            $mapelLain = $bentrokGuru->plottingMapel?->mapel?->nama ?? 'mapel lain';

            throw AturanBisnisException::tolak(
                "Bentrok guru: {$plotting->pegawai?->nama} sudah mengajar {$mapelLain} di {$kelasLain} pada "
                .$this->namaHari($hari)." jam ke-{$slot->jam_ke} (BR-06).",
                'plotting_mapel_id',
                'BR-06',
            );
        }

        // BR-07 — kelas tidak boleh punya dua mapel pada hari+slot yang sama.
        $bentrokKelas = Jadwal::with(['plottingMapel.mapel:id,nama', 'pegawai:id,nama'])
            ->where('semester_id', $semester->id)
            ->where('hari', $hari)
            ->where('slot_jam_id', $slot->id)
            ->where('kelas_id', $plotting->kelas_id)
            ->first();

        if ($bentrokKelas !== null) {
            $mapelLain = $bentrokKelas->plottingMapel?->mapel?->nama ?? 'mapel lain';
            $guruLain = $bentrokKelas->pegawai?->nama ?? 'guru lain';

            throw AturanBisnisException::tolak(
                "Bentrok kelas: {$plotting->kelas?->nama} sudah mendapat {$mapelLain} bersama {$guruLain} pada "
                .$this->namaHari($hari)." jam ke-{$slot->jam_ke} (BR-07).",
                'plotting_mapel_id',
                'BR-07',
            );
        }

        // BR-09 — jumlah entri jadwal tidak boleh melebihi jp_per_minggu plotting.
        $terjadwal = Jadwal::where('plotting_mapel_id', $plotting->id)->count();

        if ($terjadwal >= $plotting->jp_per_minggu) {
            throw AturanBisnisException::tolak(
                "Jadwal untuk {$plotting->mapel?->nama} di {$plotting->kelas?->nama} sudah mencapai "
                ."{$plotting->jp_per_minggu} JP per minggu (BR-09). Ubah JP per minggu pada plotting bila perlu.",
                'plotting_mapel_id',
                'BR-09',
            );
        }

        try {
            return DB::transaction(function () use ($semester, $hari, $slot, $plotting, $oleh): Jadwal {
                $jadwal = Jadwal::create([
                    'semester_id' => $semester->id,
                    'hari' => $hari,
                    'slot_jam_id' => $slot->id,
                    // Didenormalisasi dari plotting (7.3 catatan) agar bentrok dapat ditegakkan database.
                    'plotting_mapel_id' => $plotting->id,
                    'pegawai_id' => $plotting->pegawai_id,
                    'kelas_id' => $plotting->kelas_id,
                ]);

                $this->audit->catat(AuditLogService::AKSI_BUAT, $oleh, $jadwal, null, [
                    'hari' => $hari,
                    'slot_jam_id' => $slot->id,
                    'plotting_mapel_id' => $plotting->id,
                ]);

                return $jadwal->load(['slotJam', 'plottingMapel.mapel', 'plottingMapel.kelas', 'pegawai']);
            });
        } catch (QueryException $e) {
            // Jaring terakhir: indeks unik BR-06/BR-07 (mis. dua permintaan bersamaan).
            if ($this->galatDuplikat($e)) {
                throw AturanBisnisException::konflik(
                    'Jadwal tidak dapat disimpan karena bentrok dengan jadwal yang baru saja dibuat (BR-06/BR-07). Muat ulang halaman lalu coba lagi.',
                    'BENTROK_JADWAL',
                );
            }

            throw $e;
        }
    }

    public function hapus(Jadwal $jadwal, ?User $oleh = null): void
    {
        DB::transaction(function () use ($jadwal, $oleh): void {
            $lama = [
                'hari' => $jadwal->hari,
                'slot_jam_id' => $jadwal->slot_jam_id,
                'plotting_mapel_id' => $jadwal->plotting_mapel_id,
            ];

            $jadwal->delete();

            $this->audit->catat(AuditLogService::AKSI_HAPUS, $oleh, $jadwal, $lama, null);
        });
    }

    /**
     * FR-JDW-04 — grid jadwal. Menyertakan hari yang punya pola jam beserta slotnya,
     * sehingga sel kosong dapat langsung diklik di antarmuka.
     */
    public function grid(Semester $semester, ?int $kelasId = null, ?int $pegawaiId = null): array
    {
        $pola = PolaJam::with('slot')
            ->where('semester_id', $semester->id)
            ->get();

        $hariBerlaku = PolaJamHari::where('semester_id', $semester->id)->pluck('hari')->sort()->values();

        $query = Jadwal::with(['slotJam', 'plottingMapel.mapel:id,kode,nama', 'plottingMapel.kelas:id,nama', 'pegawai:id,nama'])
            ->where('semester_id', $semester->id);

        if ($kelasId !== null) {
            $query->where('kelas_id', $kelasId);
        }

        if ($pegawaiId !== null) {
            $query->where('pegawai_id', $pegawaiId);
        }

        $jadwal = $query->get();

        $hari = [];
        foreach ($hariBerlaku as $h) {
            $polaHari = $pola->first(fn ($p) => $p->hari->contains('hari', $h));

            $slotHari = $polaHari !== null
                ? $polaHari->slot->sortBy('urutan')->values()
                : collect();

            $baris = [];
            foreach ($slotHari as $s) {
                $isi = $jadwal
                    ->where('hari', $h)
                    ->where('slot_jam_id', $s->id)
                    ->map(fn (Jadwal $j): array => [
                        'id' => $j->id,
                        'plotting_mapel_id' => $j->plotting_mapel_id,
                        'mapel' => $j->plottingMapel?->mapel?->nama,
                        'kode_mapel' => $j->plottingMapel?->mapel?->kode,
                        'kelas' => $j->plottingMapel?->kelas?->nama,
                        'kelas_id' => $j->kelas_id,
                        'guru' => $j->pegawai?->nama,
                        'pegawai_id' => $j->pegawai_id,
                    ])
                    ->values()
                    ->all();

                $baris[] = [
                    'slot_jam_id' => $s->id,
                    'urutan' => $s->urutan,
                    'tipe' => $s->tipe,
                    'label' => $s->label,
                    'jam_mulai' => $s->jamMulaiPendek(),
                    'jam_selesai' => $s->jamSelesaiPendek(),
                    'jam_ke' => $s->jam_ke,
                    'dapat_dijadwalkan' => $s->bolehDijadwalkan(),
                    'isi' => $isi,
                ];
            }

            $hari[] = [
                'hari' => $h,
                'nama_hari' => $this->namaHari($h),
                'pola_jam' => $polaHari?->nama,
                'slot' => $baris,
            ];
        }

        return [
            // `Semester::label()` adalah method, bukan kolom — memakai akses atribut
            // ($semester->label) akan dilempar sebagai galat resolusi relasi.
            'semester' => ['id' => $semester->id, 'label' => $semester->label()],
            'hari' => $hari,
            'peringatan' => $this->peringatan($semester),
        ];
    }

    /**
     * FR-JDW-07 — peringatan (bukan blokir) bila JP terjadwal kurang dari jp_per_minggu.
     *
     * @return list<array{plotting_mapel_id: int, mapel: string|null, kelas: string|null, guru: string|null, jp_per_minggu: int, terjadwal: int}>
     */
    public function peringatan(Semester $semester): array
    {
        $hasil = [];

        $plotting = PlottingMapel::with(['mapel:id,nama', 'kelas:id,nama', 'pegawai:id,nama'])
            ->withCount('jadwal')
            ->where('semester_id', $semester->id)
            ->get();

        foreach ($plotting as $p) {
            if ($p->jadwal_count >= $p->jp_per_minggu) {
                continue;
            }

            $hasil[] = [
                'plotting_mapel_id' => $p->id,
                'mapel' => $p->mapel?->nama,
                'kelas' => $p->kelas?->nama,
                'guru' => $p->pegawai?->nama,
                'jp_per_minggu' => $p->jp_per_minggu,
                'terjadwal' => $p->jadwal_count,
            ];
        }

        return $hasil;
    }

    /** FR-JDW-06 — jadwal hari ini untuk beranda guru. */
    public function jadwalHariIni(Pegawai $guru, Semester $semester): array
    {
        $hariIni = (int) now()->isoWeekday();

        return Jadwal::with(['slotJam', 'plottingMapel.mapel:id,kode,nama', 'plottingMapel.kelas:id,nama'])
            ->where('semester_id', $semester->id)
            ->where('pegawai_id', $guru->id)
            ->where('hari', $hariIni)
            ->get()
            ->sortBy(fn (Jadwal $j): int => (int) $j->slotJam?->urutan)
            ->map(fn (Jadwal $j): array => [
                'id' => $j->id,
                'jam_mulai' => $j->slotJam?->jamMulaiPendek(),
                'jam_selesai' => $j->slotJam?->jamSelesaiPendek(),
                'jam_ke' => $j->slotJam?->jam_ke,
                'mapel' => $j->plottingMapel?->mapel?->nama,
                'kode_mapel' => $j->plottingMapel?->mapel?->kode,
                'kelas' => $j->plottingMapel?->kelas?->nama,
            ])
            ->values()
            ->all();
    }

    /** FR-JDW-06 — jadwal mingguan milik guru, dikelompokkan per hari. */
    public function jadwalMingguan(Pegawai $guru, Semester $semester): array
    {
        $jadwal = Jadwal::with(['slotJam', 'plottingMapel.mapel:id,kode,nama', 'plottingMapel.kelas:id,nama'])
            ->where('semester_id', $semester->id)
            ->where('pegawai_id', $guru->id)
            ->get();

        $hasil = [];

        for ($h = 1; $h <= 7; $h++) {
            $untukHari = $jadwal->where('hari', $h)
                ->sortBy(fn (Jadwal $j): int => (int) $j->slotJam?->urutan)
                ->map(fn (Jadwal $j): array => [
                    'id' => $j->id,
                    'jam_mulai' => $j->slotJam?->jamMulaiPendek(),
                    'jam_selesai' => $j->slotJam?->jamSelesaiPendek(),
                    'jam_ke' => $j->slotJam?->jam_ke,
                    'mapel' => $j->plottingMapel?->mapel?->nama,
                    'kode_mapel' => $j->plottingMapel?->mapel?->kode,
                    'kelas' => $j->plottingMapel?->kelas?->nama,
                ])
                ->values()
                ->all();

            if ($untukHari === []) {
                continue;
            }

            $hasil[] = [
                'hari' => $h,
                'nama_hari' => $this->namaHari($h),
                'jadwal' => $untukHari,
                'jumlah_jp' => count($untukHari),
            ];
        }

        return $hasil;
    }

    public function namaHari(int $hari): string
    {
        return PolaJamHari::NAMA[$hari] ?? (string) $hari;
    }

    private function galatDuplikat(QueryException $e): bool
    {
        $kode = (string) ($e->errorInfo[1] ?? '');

        return $kode === '1062' || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
