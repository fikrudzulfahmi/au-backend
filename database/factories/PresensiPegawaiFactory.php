<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pegawai;
use App\Models\PresensiPegawai;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PresensiPegawai> */
class PresensiPegawaiFactory extends Factory
{
    protected $model = PresensiPegawai::class;

    public function definition(): array
    {
        $tanggal = now()->toDateString();

        return [
            'pegawai_id' => Pegawai::factory(),
            'tanggal' => $tanggal,
            'semester_id' => null,

            'masuk_waktu' => $tanggal.' 07:00:00',
            'masuk_lat' => -7.8654000,
            'masuk_lng' => 111.4650000,
            'masuk_akurasi_m' => 10,
            'masuk_lokasi_id' => null,
            'masuk_jarak_m' => 5,
            'masuk_foto_path' => 'presensi/uji/masuk.jpg',
            'masuk_status' => PresensiPegawai::STATUS_HADIR,
            'masuk_menit_terlambat' => 0,
            'masuk_validasi' => PresensiPegawai::VALID,

            'pulang_waktu' => null,
            'pulang_status' => null,
            'pulang_menit_cepat' => 0,
            'pulang_validasi' => null,

            'dikoreksi_admin' => false,
        ];
    }

    /** Presensi di luar radius yang menunggu keputusan admin (BR-17 Jalur B). */
    public function menunggu(): static
    {
        return $this->state(fn (): array => [
            'masuk_lokasi_id' => null,
            'masuk_jarak_m' => 4200,
            'masuk_validasi' => PresensiPegawai::MENUNGGU,
            'masuk_alasan_luar_radius' => 'Mengantar anak ke sekolah lain.',
        ]);
    }

    public function terlambat(int $menit = 12): static
    {
        return $this->state(fn (array $atribut): array => [
            'masuk_waktu' => now()->toDateString().' 07:'.str_pad((string) $menit, 2, '0', STR_PAD_LEFT).':00',
            'masuk_status' => PresensiPegawai::STATUS_TERLAMBAT,
            'masuk_menit_terlambat' => $menit,
        ]);
    }
}
