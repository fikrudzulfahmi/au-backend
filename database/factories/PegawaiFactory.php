<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pegawai;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pegawai>
 */
class PegawaiFactory extends Factory
{
    protected $model = Pegawai::class;

    public function definition(): array
    {
        return [
            'nip' => (string) fake()->unique()->numerify('##################'),
            'nama' => fake()->name(),
            'jenis_kelamin' => fake()->randomElement(['L', 'P']),
            'tanggal_lahir' => fake()->dateTimeBetween('-55 years', '-24 years'),
            'jenis_pegawai' => Pegawai::JENIS_GURU,
            'jabatan' => 'Guru',
            'status_kepegawaian' => fake()->randomElement(Pegawai::STATUS_KEPEGAWAIAN),
            'email' => null,
            'no_hp' => null,
            'is_active' => true,
        ];
    }

    public function struktural(): static
    {
        return $this->state(fn (): array => [
            'jenis_pegawai' => Pegawai::JENIS_STRUKTURAL,
            'jabatan' => 'Kepala Tata Usaha',
        ]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
