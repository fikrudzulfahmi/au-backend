<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PengajuanIzin> */
class PengajuanIzinFactory extends Factory
{
    protected $model = PengajuanIzin::class;

    public function definition(): array
    {
        return [
            'pegawai_id' => Pegawai::factory(),
            'jenis' => PengajuanIzin::JENIS_IZIN,
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->toDateString(),
            'alasan' => $this->faker->sentence(6),
            'lampiran_path' => null,
            'presensi_luar_radius' => false,
            'status' => PengajuanIzin::STATUS_MENUNGGU,
            'dibuat_oleh_admin' => false,
        ];
    }

    public function disetujui(): static
    {
        return $this->state(fn (): array => ['status' => PengajuanIzin::STATUS_DISETUJUI]);
    }

    public function dinas(bool $luarRadius = false): static
    {
        return $this->state(fn (): array => [
            'jenis' => PengajuanIzin::JENIS_DINAS,
            'presensi_luar_radius' => $luarRadius,
        ]);
    }
}
