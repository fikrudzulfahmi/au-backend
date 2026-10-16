<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Penugasan guru piket — siapa bertugas pada hari apa (1 = Senin .. 7 = Minggu). */
class PenugasanPiketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'semester_id' => ['required', 'integer', 'exists:semester,id'],
            // Hanya guru aktif yang boleh dipiketkan: piket adalah penugasan
            // mengajar pengganti, bukan penugasan administratif.
            'pegawai_id' => [
                'required',
                'integer',
                Rule::exists('pegawai', 'id')->where('jenis_pegawai', 'guru')->where('is_active', true),
            ],
            'hari' => ['required', 'integer', 'between:1,7'],
            'catatan' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'semester_id' => 'Semester',
            'pegawai_id' => 'Guru',
            'hari' => 'Hari',
            'catatan' => 'Catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'pegawai_id.exists' => 'Guru yang dipilih tidak aktif atau bukan berjenis guru.',
            'hari.between' => 'Hari harus di antara 1 (Senin) sampai 7 (Minggu).',
        ];
    }
}
