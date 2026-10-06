<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PolaJam;
use App\Models\PolaJamHari;
use App\Models\Semester;
use App\Models\SlotJam;
use App\Models\TahunPelajaran;
use Illuminate\Database\Seeder;

/** 10 — pola jam Senin–Kamis dan Jumat untuk semester aktif (FR-JAM-01). */
class JamPelajaranSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: int|null}> */
    private const SLOT_SENIN_KAMIS = [
        ['pelajaran', 'Jam ke-1', '07:00', '07:45', 1],
        ['pelajaran', 'Jam ke-2', '07:45', '08:30', 2],
        ['pelajaran', 'Jam ke-3', '08:30', '09:15', 3],
        ['istirahat', 'Istirahat', '09:15', '09:30', null],
        ['pelajaran', 'Jam ke-4', '09:30', '10:15', 4],
        ['pelajaran', 'Jam ke-5', '10:15', '11:00', 5],
        ['istirahat', 'Istirahat', '11:00', '11:15', null],
        ['pelajaran', 'Jam ke-6', '11:15', '12:00', 6],
        ['pelajaran', 'Jam ke-7', '12:00', '12:45', 7],
        ['pelajaran', 'Jam ke-8', '12:45', '13:30', 8],
    ];

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: int|null}> */
    private const SLOT_JUMAT = [
        ['pelajaran', 'Jam ke-1', '07:00', '07:40', 1],
        ['pelajaran', 'Jam ke-2', '07:40', '08:20', 2],
        ['istirahat', 'Istirahat', '08:20', '08:35', null],
        ['pelajaran', 'Jam ke-3', '08:35', '09:15', 3],
        ['pelajaran', 'Jam ke-4', '09:15', '09:55', 4],
        ['kegiatan', 'Kegiatan Jumat', '09:55', '10:40', null],
    ];

    public function run(): void
    {
        $tahun = TahunPelajaran::where('status', TahunPelajaran::STATUS_AKTIF)->first();

        if ($tahun === null) {
            return;
        }

        $semester = Semester::where('tahun_pelajaran_id', $tahun->id)->where('is_active', true)->first()
            ?? Semester::where('tahun_pelajaran_id', $tahun->id)->orderBy('jenis')->first();

        if ($semester === null) {
            return;
        }

        $this->buatPola($semester, 'Senin–Kamis', [1, 2, 3, 4], self::SLOT_SENIN_KAMIS);
        $this->buatPola($semester, 'Jumat', [5], self::SLOT_JUMAT);
    }

    /**
     * @param  list<int>  $hari
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int|null}>  $slot
     */
    private function buatPola(Semester $semester, string $nama, array $hari, array $slot): void
    {
        $pola = PolaJam::updateOrCreate(
            ['semester_id' => $semester->id, 'nama' => $nama],
            [],
        );

        foreach ($hari as $h) {
            PolaJamHari::updateOrCreate(
                ['semester_id' => $semester->id, 'hari' => $h],
                ['pola_jam_id' => $pola->id],
            );
        }

        foreach ($slot as $indeks => [$tipe, $label, $mulai, $selesai, $jamKe]) {
            SlotJam::updateOrCreate(
                ['pola_jam_id' => $pola->id, 'urutan' => $indeks + 1],
                [
                    'tipe' => $tipe,
                    'label' => $label,
                    'jam_mulai' => $mulai,
                    'jam_selesai' => $selesai,
                    'jam_ke' => $jamKe,
                ],
            );
        }
    }
}
