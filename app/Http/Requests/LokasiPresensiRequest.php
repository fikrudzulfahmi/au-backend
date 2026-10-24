<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'radius_m' => ['required', 'integer', 'min:10', 'max:10000'],
            'poligon' => ['nullable', 'array', 'min:3', 'max:200'],
            'poligon.*' => ['array', 'size:2'],
            'poligon.*.0' => ['required', 'numeric', 'between:-90,90'],
            'poligon.*.1' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
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
