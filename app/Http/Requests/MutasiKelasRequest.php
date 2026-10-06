<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-PLK-05 — mutasi siswa antar kelas; alasan wajib. */
class MutasiKelasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kelas_tujuan_id' => ['required', 'integer', 'exists:kelas,id'],
            'tanggal' => ['required', 'date'],
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kelas_tujuan_id.required' => 'Kelas tujuan wajib dipilih.',
            'kelas_tujuan_id.exists' => 'Kelas tujuan tidak ditemukan.',
            'tanggal.required' => 'Tanggal mutasi wajib diisi.',
            'alasan.required' => 'Alasan mutasi wajib diisi.',
            'alasan.min' => 'Alasan mutasi terlalu pendek — tuliskan alasannya dengan jelas.',
        ];
    }
}
