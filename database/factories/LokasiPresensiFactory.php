<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LokasiPresensi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LokasiPresensi>
 *
 * Bawaan sengaja `is_default = false`: hanya satu lokasi boleh default (BR-12),
 * sehingga uji yang membutuhkan default harus menetapkannya secara sadar.
 */
class LokasiPresensiFactory extends Factory
{
    protected $model = LokasiPresensi::class;

    public function definition(): array
    {
        return [
            'nama' => 'Lokasi '.$this->faker->unique()->city(),
            // Koordinat di sekitar Ponorogo agar realistis untuk sekolah ini.
            'latitude' => $this->faker->randomFloat(7, -7.95, -7.80),
            'longitude' => $this->faker->randomFloat(7, 111.40, 111.55),
            'radius_m' => 100,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
