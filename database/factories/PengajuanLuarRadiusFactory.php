<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pegawai;
use App\Models\PengajuanLuarRadius;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PengajuanLuarRadius> */
class PengajuanLuarRadiusFactory extends Factory
{
    protected $model = PengajuanLuarRadius::class;

    public function definition(): array
    {
        return [
            'pegawai_id' => Pegawai::factory(),
            'tanggal' => now()->toDateString(),
            'alasan' => $this->faker->sentence(6),
            'lampiran_path' => null,
            'pengajuan_izin_id' => null,
            'status' => PengajuanLuarRadius::STATUS_MENUNGGU,
        ];
    }

    public function disetujui(): static
    {
        return $this->state(fn (): array => ['status' => PengajuanLuarRadius::STATUS_DISETUJUI]);
    }
}
