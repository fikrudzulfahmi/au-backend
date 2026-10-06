<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-JDW-01 — satu entri jadwal. Bentrok (BR-06/07), BR-08, dan BR-09 dijaga service. */
class JadwalRequest extends FormRequest
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
            'hari' => ['required', 'integer', 'between:1,7'],
            'slot_jam_id' => ['required', 'integer', 'exists:slot_jam,id'],
            'plotting_mapel_id' => ['required', 'integer', 'exists:plotting_mapel,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'semester_id.required' => 'Semester wajib dipilih.',
            'hari.required' => 'Hari wajib dipilih.',
            'hari.between' => 'Hari harus antara 1 (Senin) dan 7 (Minggu).',
            'slot_jam_id.required' => 'Slot jam wajib dipilih.',
            'plotting_mapel_id.required' => 'Mata pelajaran dan kelas wajib dipilih.',
        ];
    }
}
