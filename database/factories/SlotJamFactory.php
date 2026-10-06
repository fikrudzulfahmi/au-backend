<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PolaJam;
use App\Models\SlotJam;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlotJam> */
class SlotJamFactory extends Factory
{
    protected $model = SlotJam::class;

    public function definition(): array
    {
        return [
            'pola_jam_id' => PolaJam::factory(),
            'urutan' => 1,
            'tipe' => SlotJam::PELAJARAN,
            'label' => 'Jam ke-1',
            'jam_mulai' => '07:00',
            'jam_selesai' => '07:45',
            'jam_ke' => 1,
        ];
    }
}
