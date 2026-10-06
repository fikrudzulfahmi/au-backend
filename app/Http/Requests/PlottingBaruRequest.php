<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-PLK-01 — menempatkan siswa yang belum terplot ke sebuah kelas. */
class PlottingBaruRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kelas_id' => ['required', 'integer', 'exists:kelas,id'],
            'siswa_ids' => ['required', 'array', 'min:1'],
            'siswa_ids.*' => ['integer', 'exists:siswa,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kelas_id.required' => 'Kelas tujuan wajib dipilih.',
            'kelas_id.exists' => 'Kelas tujuan tidak ditemukan.',
            'siswa_ids.required' => 'Pilih minimal satu siswa.',
            'siswa_ids.min' => 'Pilih minimal satu siswa.',
        ];
    }
}
