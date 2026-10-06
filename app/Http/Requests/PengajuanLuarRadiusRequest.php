<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-IZN-06 — pengajuan presensi luar radius mandiri (satu tanggal). */
class PengajuanLuarRadiusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'alasan' => ['required', 'string', 'min:5', 'max:1000'],
            'lampiran' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:2048'],
        ];
    }
}
