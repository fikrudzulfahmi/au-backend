<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-PRS — presensi masuk/pulang.
 *
 * Foto dan koordinat divalidasi di lapisan ini sebagai bentuk pertama; aturan
 * bisnisnya (KP-3.1, BR-29) tetap ditegakkan ulang di PresensiService agar tidak
 * bergantung pada permintaan HTTP saja.
 */
class PresensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Hanya dari kamera: galeri ditolak di sisi klien; di server dipastikan
            // berkasnya berupa gambar yang benar-benar diunggah.
            'foto' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'akurasi_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'alasan' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'foto' => 'Foto presensi',
            'lat' => 'Koordinat lintang',
            'lng' => 'Koordinat bujur',
            'akurasi_m' => 'Akurasi GPS',
            'alasan' => 'Alasan di luar radius',
        ];
    }
}
