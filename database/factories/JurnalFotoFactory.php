<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Jurnal;
use App\Models\JurnalFoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JurnalFoto> */
class JurnalFotoFactory extends Factory
{
    protected $model = JurnalFoto::class;

    public function definition(): array
    {
        return [
            'jurnal_id' => Jurnal::factory(),
            'foto_path' => 'jurnal/uji/kegiatan-'.fake()->numberBetween(1, 999).'.jpg',
            'urutan' => 1,
            'foto_dihapus_pada' => null,
        ];
    }

    public function urutan(int $urutan): static
    {
        return $this->state(fn (): array => ['urutan' => $urutan]);
    }

    /** Foto yang berkasnya sudah dibuang karena retensi. */
    public function terhapus(): static
    {
        return $this->state(fn (): array => ['foto_dihapus_pada' => now()]);
    }
}
