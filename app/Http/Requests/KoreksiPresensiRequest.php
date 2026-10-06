<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-PRS-13 — koreksi manual presensi oleh admin, wajib beralasan. */
class KoreksiPresensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
            'masuk_waktu' => ['nullable', 'date'],
            'pulang_waktu' => ['nullable', 'date'],
            'masuk_status' => ['nullable', 'in:hadir,terlambat'],
            'pulang_status' => ['nullable', 'in:normal,pulang_cepat'],
            'masuk_validasi' => ['nullable', 'in:valid,menunggu,disetujui,ditolak'],
            'pulang_validasi' => ['nullable', 'in:valid,menunggu,disetujui,ditolak'],
            'catatan_penyetuju' => ['nullable', 'string', 'max:500'],
        ];
    }
}
