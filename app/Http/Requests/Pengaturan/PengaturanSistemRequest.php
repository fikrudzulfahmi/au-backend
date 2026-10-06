<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengaturan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-LOK-05 / FR-LOK-06 — parameter teknis presensi.
 * Nilai tidak di-hardcode: seluruhnya diubah admin tanpa deploy ulang.
 */
class PengaturanSistemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gps_max_akurasi_m' => ['required', 'integer', 'min:5', 'max:500'],
            'foto_max_sisi_px' => ['required', 'integer', 'min:200', 'max:2000'],
            'foto_kualitas_jpeg' => ['required', 'integer', 'min:20', 'max:100'],
            'foto_target_maks_kb' => ['required', 'integer', 'min:50', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'gps_max_akurasi_m.max' => 'Akurasi GPS maksimal 500 meter.',
            'foto_max_sisi_px.max' => 'Sisi terpanjang foto maksimal 2000 piksel.',
            'foto_kualitas_jpeg.min' => 'Kualitas JPEG minimal 20.',
            'foto_target_maks_kb.max' => 'Target ukuran foto maksimal 1000 KB.',
        ];
    }
}
