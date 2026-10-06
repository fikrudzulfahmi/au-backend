<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SlotJam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-JAM-02 — satu slot jam. Aturan tumpang tindih & jam_ke dijaga service. */
class SlotJamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'urutan' => ['required', 'integer', 'min:1', 'max:100'],
            'tipe' => ['required', Rule::in(SlotJam::TIPE)],
            'label' => ['required', 'string', 'max:100'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i'],
            'jam_ke' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tipe.in' => 'Tipe slot harus pelajaran, istirahat, atau kegiatan.',
            'label.required' => 'Label slot wajib diisi.',
            'jam_mulai.date_format' => 'Jam mulai harus berformat HH:MM.',
            'jam_selesai.date_format' => 'Jam selesai harus berformat HH:MM.',
        ];
    }
}
