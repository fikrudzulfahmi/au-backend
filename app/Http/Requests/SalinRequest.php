<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-PLM-05 / FR-JAM-04 — salin plotting mapel atau pola jam dari semester lain. */
class SalinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'semester_asal_id' => ['required', 'integer', 'exists:semester,id'],
            'semester_tujuan_id' => ['required', 'integer', 'different:semester_asal_id', 'exists:semester,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'semester_asal_id.required' => 'Semester asal wajib dipilih.',
            'semester_tujuan_id.required' => 'Semester tujuan wajib dipilih.',
            'semester_tujuan_id.different' => 'Semester asal dan tujuan tidak boleh sama.',
        ];
    }
}
