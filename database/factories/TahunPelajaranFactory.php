<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TahunPelajaran;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TahunPelajaran> */
class TahunPelajaranFactory extends Factory
{
    protected $model = TahunPelajaran::class;

    /** Penghitung agar `nama` selalu unik antar factory dalam satu proses uji. */
    private static int $urutan = 0;

    public function definition(): array
    {
        $awal = 2018 + (self::$urutan++);

        return [
            'nama' => $awal.'/'.($awal + 1),
            'tanggal_mulai' => $awal.'-07-01',
            'tanggal_selesai' => ($awal + 1).'-06-30',
            'status' => TahunPelajaran::STATUS_DRAFT,
        ];
    }

    /** Tahun pelajaran aktif beserta semester aktifnya. */
    public function aktif(string $jenisSemester = 'ganjil'): static
    {
        return $this->afterCreating(function (TahunPelajaran $tahun) use ($jenisSemester): void {
            $tahun->semester()->createMany([
                ['jenis' => 'ganjil', 'tanggal_mulai' => $tahun->tanggal_mulai, 'is_active' => $jenisSemester === 'ganjil'],
                ['jenis' => 'genap', 'tanggal_mulai' => $tahun->tanggal_selesai, 'is_active' => $jenisSemester === 'genap'],
            ]);

            $tahun->forceFill(['status' => TahunPelajaran::STATUS_AKTIF])->save();
        });
    }

    /** Sekalian membuat dua semester tanpa mengaktifkan tahun pelajaran. */
    public function denganSemester(): static
    {
        return $this->afterCreating(function (TahunPelajaran $tahun): void {
            $tahun->semester()->createMany([
                ['jenis' => 'ganjil', 'tanggal_mulai' => $tahun->tanggal_mulai, 'is_active' => false],
                ['jenis' => 'genap', 'tanggal_mulai' => $tahun->tanggal_selesai, 'is_active' => false],
            ]);
        });
    }
}
