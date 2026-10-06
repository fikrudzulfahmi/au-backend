<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-LOK-03 — penetapan lokasi per pegawai (mendukung massal). */
class TetapkanLokasiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pegawai_ids' => ['required', 'array', 'min:1'],
            'pegawai_ids.*' => ['integer', 'exists:pegawai,id'],
            'lokasi_ids' => ['present', 'array'],
            'lokasi_ids.*' => ['integer', 'exists:lokasi_presensi,id'],
        ];
    }
}
