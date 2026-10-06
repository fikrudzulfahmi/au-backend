<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\PolaJam;
use App\Models\PolaJamHari;
use App\Models\Semester;
use App\Models\SlotJam;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** FR-JAM — pola jam dan slotnya per semester. BR-05: satu hari satu pola. */
final class JamPelajaranService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * FR-JAM-01 — menyimpan pola jam beserta hari berlakunya.
     * BR-05: satu hari hanya boleh masuk ke satu pola dalam satu semester.
     *
     * @param  list<int|string>  $hari
     */
    public function simpanPola(?PolaJam $pola, Semester $semester, string $nama, array $hari, ?User $oleh = null): PolaJam
    {
        $nama = trim($nama);

        if ($nama === '') {
            throw AturanBisnisException::tolak('Nama pola jam wajib diisi.', 'nama');
        }

        $hari = array_values(array_unique(array_map('intval', $hari)));

        if ($hari === []) {
            throw AturanBisnisException::tolak('Pilih minimal satu hari untuk pola jam ini.', 'hari');
        }

        foreach ($hari as $h) {
            if ($h < 1 || $h > 7) {
                throw AturanBisnisException::tolak("Hari {$h} tidak dikenali (1=Senin … 7=Minggu).", 'hari');
            }
        }

        // BR-05 — hari yang sudah dipakai pola lain pada semester yang sama.
        $terpakai = PolaJamHari::query()
            ->where('semester_id', $semester->id)
            ->whereIn('hari', $hari)
            ->when($pola !== null, fn ($q) => $q->where('pola_jam_id', '!=', $pola->id))
            ->get();

        if ($terpakai->isNotEmpty()) {
            $namaHari = $terpakai->map(fn (PolaJamHari $x): string => $x->namaHari())->implode(', ');
            $polaLain = PolaJam::find($terpakai->first()->pola_jam_id)?->nama;

            throw AturanBisnisException::kumpulan(
                "Hari berikut sudah dipakai pola lain pada semester ini: {$namaHari}. Satu hari hanya boleh masuk satu pola (BR-05).",
                ["Hari {$namaHari} sudah dipakai pola \"{$polaLain}\"."],
                'hari',
                'BR-05',
            );
        }

        return DB::transaction(function () use ($pola, $semester, $nama, $hari, $oleh): PolaJam {
            if ($pola === null) {
                $pola = PolaJam::create(['semester_id' => $semester->id, 'nama' => $nama]);
                $aksi = AuditLogService::AKSI_BUAT;
            } else {
                $pola->nama = $nama;
                $pola->save();
                $aksi = AuditLogService::AKSI_UBAH;
            }

            $pola->hari()->delete();

            foreach ($hari as $h) {
                PolaJamHari::create([
                    'pola_jam_id' => $pola->id,
                    'semester_id' => $semester->id,
                    'hari' => $h,
                ]);
            }

            $this->audit->catat($aksi, $oleh, $pola, null, ['nama' => $nama, 'hari' => $hari]);

            return $pola->load(['hari', 'slot']);
        });
    }

    public function hapusPola(PolaJam $pola, ?User $oleh = null): void
    {
        $dipakai = SlotJam::whereIn('id', $pola->slot()->pluck('id'))->whereHas('jadwal')->count();

        if ($dipakai > 0) {
            throw AturanBisnisException::konflik(
                'Pola jam ini masih dipakai jadwal. Hapus jadwal pada pola ini lebih dahulu.',
                'DIPAKAI_JADWAL',
            );
        }

        DB::transaction(function () use ($pola, $oleh): void {
            $lama = ['nama' => $pola->nama];
            $pola->delete();
            $this->audit->catat(AuditLogService::AKSI_HAPUS, $oleh, $pola, $lama, null);
        });
    }

    /**
     * FR-JAM-02/03 — menambah atau mengubah slot jam.
     * Validasi: jam mulai < selesai, tidak tumpang tindih, jam_ke wajib untuk tipe
     * pelajaran dan harus unik serta berurutan dalam satu pola.
     *
     * @param  array{urutan: int, tipe: string, label: string, jam_mulai: string, jam_selesai: string, jam_ke?: int|null}  $data
     */
    public function simpanSlot(PolaJam $pola, array $data, ?SlotJam $slot = null, ?User $oleh = null): SlotJam
    {
        $tipe = (string) $data['tipe'];

        if (! in_array($tipe, SlotJam::TIPE, true)) {
            throw AturanBisnisException::tolak('Tipe slot harus pelajaran, istirahat, atau kegiatan.', 'tipe');
        }

        $mulai = $this->normalisasiJam((string) $data['jam_mulai']);
        $selesai = $this->normalisasiJam((string) $data['jam_selesai']);

        if ($mulai >= $selesai) {
            throw AturanBisnisException::tolak('Jam mulai harus lebih awal daripada jam selesai.', 'jam_selesai');
        }

        $jamKe = $data['jam_ke'] ?? null;
        $jamKe = ($jamKe === null || $jamKe === '') ? null : (int) $jamKe;

        if ($tipe === SlotJam::PELAJARAN && $jamKe === null) {
            throw AturanBisnisException::tolak('Jam ke- wajib diisi untuk slot bertipe pelajaran.', 'jam_ke');
        }

        if ($tipe !== SlotJam::PELAJARAN) {
            $jamKe = null;
        }

        // FR-JAM-05 — jam ke- tidak boleh diubah bila slot sudah dipakai jadwal.
        if ($slot !== null && $slot->jam_ke !== $jamKe && $slot->jadwal()->exists()) {
            throw AturanBisnisException::konflik(
                'Jam ke- tidak dapat diubah karena slot ini sudah dipakai jadwal (FR-JAM-05). Hapus jadwalnya lebih dahulu.',
                'DIPAKAI_JADWAL',
            );
        }

        // Tumpang tindih dengan slot lain pada pola yang sama.
        $lain = SlotJam::query()->where('pola_jam_id', $pola->id)
            ->when($slot !== null, fn ($q) => $q->where('id', '!=', $slot->id))
            ->get();

        foreach ($lain as $l) {
            $lMulai = $this->normalisasiJam((string) $l->jam_mulai);
            $lSelesai = $this->normalisasiJam((string) $l->jam_selesai);

            if ($mulai < $lSelesai && $lMulai < $selesai) {
                throw AturanBisnisException::tolak(
                    "Slot tumpang tindih dengan \"{$l->label}\" ({$lMulai}–{$lSelesai}). Perbaiki jamnya (FR-JAM-03).",
                    'jam_mulai',
                );
            }
        }

        if ($jamKe !== null) {
            $bentrokJamKe = SlotJam::query()->where('pola_jam_id', $pola->id)
                ->where('jam_ke', $jamKe)
                ->when($slot !== null, fn ($q) => $q->where('id', '!=', $slot->id))
                ->exists();

            if ($bentrokJamKe) {
                throw AturanBisnisException::tolak("Jam ke-{$jamKe} sudah dipakai slot lain pada pola ini.", 'jam_ke');
            }
        }

        return DB::transaction(function () use ($pola, $data, $slot, $jamKe, $tipe, $mulai, $selesai, $oleh): SlotJam {
            $isi = [
                'urutan' => (int) $data['urutan'],
                'tipe' => $tipe,
                'label' => trim((string) $data['label']),
                'jam_mulai' => $mulai,
                'jam_selesai' => $selesai,
                'jam_ke' => $jamKe,
            ];

            if (trim($isi['label']) === '') {
                throw AturanBisnisException::tolak('Label slot wajib diisi.', 'label');
            }

            if ($slot === null) {
                $slot = $pola->slot()->create($isi);
                $aksi = AuditLogService::AKSI_BUAT;
            } else {
                $slot->fill($isi)->save();
                $aksi = AuditLogService::AKSI_UBAH;
            }

            // Jam ke- harus berurutan tanpa bolong (FR-JAM-03).
            $this->pastikanJamKeBerurutan($pola);

            $this->audit->catat($aksi, $oleh, $slot, null, $isi);

            return $slot;
        });
    }

    /** FR-JAM-05 — slot yang sudah dipakai jadwal tidak boleh dihapus. */
    public function hapusSlot(SlotJam $slot, ?User $oleh = null): void
    {
        $jumlah = $slot->jadwal()->count();

        if ($jumlah > 0) {
            throw AturanBisnisException::konflik(
                "Slot ini dipakai oleh {$jumlah} entri jadwal. Hapus jadwalnya lebih dahulu (FR-JAM-05).",
                'DIPAKAI_JADWAL',
            );
        }

        DB::transaction(function () use ($slot, $oleh): void {
            $lama = ['label' => $slot->label, 'jam_ke' => $slot->jam_ke];
            $pola = $slot->polaJam;
            $slot->delete();

            if ($pola !== null) {
                $this->pastikanJamKeBerurutan($pola);
            }

            $this->audit->catat(AuditLogService::AKSI_HAPUS, $oleh, $slot, $lama, null);
        });
    }

    /**
     * FR-JAM-04 — salin pola jam (beserta slotnya) dari semester lain.
     * Hari yang sudah dipakai pada semester tujuan dilewati agar BR-05 tidak dilanggar.
     *
     * @return array{dibuat: int, dilewati: list<array{nama: string, alasan: string}>}
     */
    public function salin(Semester $asal, Semester $tujuan, ?User $oleh = null): array
    {
        if ($asal->id === $tujuan->id) {
            throw AturanBisnisException::tolak('Semester asal dan tujuan tidak boleh sama.', 'semester_id');
        }

        $dibuat = 0;
        $dilewati = [];

        DB::transaction(function () use ($asal, $tujuan, $oleh, &$dibuat, &$dilewati): void {
            $hariTerpakai = PolaJamHari::where('semester_id', $tujuan->id)->pluck('hari')->all();

            foreach (PolaJam::with('slot')->where('semester_id', $asal->id)->get() as $sumber) {
                $hariSumber = $sumber->hari()->pluck('hari')->all();
                $bentrok = array_intersect($hariSumber, $hariTerpakai);

                if ($bentrok !== []) {
                    $dilewati[] = [
                        'nama' => $sumber->nama,
                        'alasan' => 'Hari '.implode(', ', $bentrok).' sudah dipakai pola lain (BR-05).',
                    ];

                    continue;
                }

                $baru = PolaJam::create(['semester_id' => $tujuan->id, 'nama' => $sumber->nama]);

                foreach ($hariSumber as $h) {
                    PolaJamHari::create([
                        'pola_jam_id' => $baru->id,
                        'semester_id' => $tujuan->id,
                        'hari' => $h,
                    ]);
                    $hariTerpakai[] = $h;
                }

                foreach ($sumber->slot as $s) {
                    $baru->slot()->create([
                        'urutan' => $s->urutan,
                        'tipe' => $s->tipe,
                        'label' => $s->label,
                        'jam_mulai' => $s->jam_mulai,
                        'jam_selesai' => $s->jam_selesai,
                        'jam_ke' => $s->jam_ke,
                    ]);
                }

                $dibuat++;
            }

            $this->audit->catat(AuditLogService::AKSI_SALIN, $oleh, $tujuan, ['semester_asal_id' => $asal->id], [
                'pola_dibuat' => $dibuat,
            ]);
        });

        return ['dibuat' => $dibuat, 'dilewati' => $dilewati];
    }

    /** Slot pelajaran pada pola untuk hari tertentu — dipakai pengecekan BR-08. */
    public function polaUntukHari(Semester $semester, int $hari): ?PolaJam
    {
        $baris = PolaJamHari::where('semester_id', $semester->id)->where('hari', $hari)->first();

        return $baris?->polaJam;
    }

    /** FR-JAM-03 — jam ke- pada slot pelajaran harus 1..N tanpa bolong. */
    private function pastikanJamKeBerurutan(PolaJam $pola): void
    {
        $jamKe = $pola->slot()
            ->where('tipe', SlotJam::PELAJARAN)
            ->orderBy('jam_ke')
            ->pluck('jam_ke')
            ->map(fn ($v): int => (int) $v)
            ->all();

        $harusnya = $jamKe === [] ? [] : range(1, count($jamKe));

        if ($jamKe !== $harusnya) {
            throw AturanBisnisException::tolak(
                'Jam ke- pada slot pelajaran harus berurutan mulai dari 1 tanpa bolong. Nilai saat ini: '.implode(', ', $jamKe).'.',
                'jam_ke',
            );
        }
    }

    /** Mengubah "07:00" atau "07:00:00" menjadi "07:00:00". */
    private function normalisasiJam(string $jam): string
    {
        $jam = trim($jam);

        if (preg_match('/^\d{2}:\d{2}$/', $jam) === 1) {
            return $jam.':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $jam) === 1) {
            return $jam;
        }

        throw AturanBisnisException::tolak("Format jam \"{$jam}\" tidak dikenali (gunakan HH:MM).", 'jam_mulai');
    }
}
