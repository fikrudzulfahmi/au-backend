<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\JadwalWfa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JadwalWfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pegawai_id' => ['required', 'integer', 'exists:pegawai,id'],
            'per_hari' => ['required', 'array'],
            'per_hari.*.ada_wfa' => ['required', 'boolean'],
            'per_hari.*.jenis' => ['nullable', Rule::in(array_keys(JadwalWfa::DAFTAR_JENIS))],
            'per_hari.*.jam_masuk' => ['nullable', 'date_format:H:i,H:i:s'],
            'per_hari.*.keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'per_hari.*.jenis.in' => 'Jenis WFA tidak valid.',
            'per_hari.*.jam_masuk.date_format' => 'Format jam masuk harus HH:MM.',
        ];
    }
}
