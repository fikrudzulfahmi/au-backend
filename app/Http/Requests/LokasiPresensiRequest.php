<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** FR-LOK-01 — master lokasi presensi. */
class LokasiPresensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Radius 0 sah selama zona poligon terisi: klien memakai poligon sebagai
            // satu-satunya batas dan tidak perlu lingkaran radius sebagai cadangan.
            'radius_m' => ['required', 'integer', 'min:0', 'max:10000'],
            'poligon' => ['nullable', 'array', 'min:3', 'max:200'],
            'poligon.*' => ['array', 'size:2'],
            'poligon.*.0' => ['required', 'numeric', 'between:-90,90'],
            'poligon.*.1' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Radius 0 hanya bermakna bila ada poligon; tanpa keduanya lokasi tidak punya
     * batas sama sekali sehingga presensi tidak akan pernah sah.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $radius = (int) $this->input('radius_m', 0);
                $poligon = $this->input('poligon');

                if ($radius < 1 && (! is_array($poligon) || count($poligon) < 3)) {
                    $validator->errors()->add(
                        'radius_m',
                        'Isi radius minimal 10 meter, atau gambar zona poligon minimal 3 titik.',
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'poligon.array' => 'Zona poligon harus berupa daftar titik koordinat.',
            'poligon.min' => 'Zona poligon minimal memiliki 3 titik koordinat.',
            'poligon.max' => 'Zona poligon maksimal memiliki 200 titik koordinat.',
            'poligon.*.array' => 'Setiap titik poligon harus berupa array koordinat [latitude, longitude].',
            'poligon.*.size' => 'Setiap titik poligon harus berisi pasangan latitude dan longitude.',
            'poligon.*.0.required' => 'Latitude titik poligon wajib diisi.',
            'poligon.*.0.numeric' => 'Latitude titik poligon harus berupa angka.',
            'poligon.*.0.between' => 'Latitude titik poligon harus antara -90 sampai 90 derajat.',
            'poligon.*.1.required' => 'Longitude titik poligon wajib diisi.',
            'poligon.*.1.numeric' => 'Longitude titik poligon harus berupa angka.',
            'poligon.*.1.between' => 'Longitude titik poligon harus antara -180 sampai 180 derajat.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nama' => 'Nama lokasi',
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
            'radius_m' => 'Radius (meter)',
            'poligon' => 'Zona poligon',
            'poligon.*' => 'Titik poligon',
            'poligon.*.0' => 'Latitude titik poligon',
            'poligon.*.1' => 'Longitude titik poligon',
        ];
    }
}
