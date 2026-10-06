<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PresensiPegawai;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-PRS-11 — keputusan admin atas presensi luar radius. */
class PutuskanPresensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keputusan' => ['required', Rule::in([PresensiPegawai::DISETUJUI, PresensiPegawai::DITOLAK])],
            'sisi' => ['sometimes', Rule::in(['masuk', 'pulang'])],
            'catatan_penyetuju' => ['nullable', 'string', 'max:500'],
        ];
    }
}
