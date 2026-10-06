<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengaturan;

use Illuminate\Foundation\Http\FormRequest;

/** FR-KOP-04 — tata letak blok tanda tangan (singleton). */
class PengaturanTtdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kota_penetapan' => ['nullable', 'string', 'max:191'],
            'mode_tanggal' => ['required', 'in:otomatis,manual'],
            'tanggal_manual' => ['nullable', 'date_format:Y-m-d', 'required_if:mode_tanggal,manual'],
            'posisi' => ['required', 'in:kanan,kiri,dua_kolom'],
            'tampilkan_mengetahui' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'mode_tanggal.required' => 'Mode tanggal penetapan wajib dipilih.',
            'mode_tanggal.in' => 'Mode tanggal hanya boleh "otomatis" atau "manual".',
            'tanggal_manual.required_if' => 'Tanggal manual wajib diisi bila mode tanggal manual.',
            'posisi.required' => 'Posisi tanda tangan wajib dipilih.',
            'posisi.in' => 'Posisi tanda tangan hanya boleh kanan, kiri, atau dua kolom.',
        ];
    }
}
