<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProfilSekolah;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfilSekolah>
 */
class ProfilSekolahFactory extends Factory
{
    protected $model = ProfilSekolah::class;

    public function definition(): array
    {
        return [
            'nama_sekolah' => 'SMK Contoh Negeri',
            'npsn' => fake()->unique()->numerify('########'),
            'status_sekolah' => 'swasta',
            'tagline' => 'Presensi & Jurnal Digital',
            'nama_kepala_sekolah' => fake()->name(),
            'nip_kepala_sekolah' => fake()->numerify('##################'),
            'alamat_jalan' => 'Jl. Contoh No. 1',
            'desa_kelurahan' => 'Desa Contoh',
            'kecamatan' => 'Kecamatan Contoh',
            'kabupaten_kota' => 'Kabupaten Contoh',
            'provinsi' => 'Jawa Timur',
            'media_sosial' => ['instagram' => null, 'facebook' => null],
            'latitude' => null,
            'longitude' => null,
        ];
    }
}
