<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Jurnal;
use App\Models\PresensiSiswa;
use App\Models\Siswa;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PresensiSiswa> */
class PresensiSiswaFactory extends Factory
{
    protected $model = PresensiSiswa::class;

    public function definition(): array
    {
        return [
            'jurnal_id' => Jurnal::factory(),
            'siswa_id' => Siswa::factory(),
            'status' => PresensiSiswa::HADIR,
            'keterangan' => null,
        ];
    }

    /** Status selain hadir wajib disertai alasan singkat (FR-JRN-03). */
    public function tidakHadir(string $status, ?string $keterangan = null): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'keterangan' => $keterangan,
        ]);
    }
}
