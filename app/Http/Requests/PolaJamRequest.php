<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-JAM-01 — pola jam dan hari berlakunya (BR-05 dijaga service). */
class PolaJamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'semester_id' => ['required', 'integer', 'exists:semester,id'],
            'nama' => ['required', 'string', 'max:100'],
            'hari' => ['required', 'array', 'min:1'],
            'hari.*' => ['integer', 'between:1,7'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'semester_id.required' => 'Semester wajib dipilih.',
            'nama.required' => 'Nama pola jam wajib diisi.',
            'hari.required' => 'Pilih minimal satu hari.',
            'hari.*.between' => 'Hari harus antara 1 (Senin) dan 7 (Minggu).',
        ];
    }
}
