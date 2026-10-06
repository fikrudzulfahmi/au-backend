<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-IZN-04 — keputusan atas pengajuan; catatan wajib saat menolak. */
class PutuskanPengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['disetujui', 'ditolak'])],
            'catatan_penyetuju' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === 'ditolak'),
                'nullable', 'string', 'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return ['catatan_penyetuju.required' => 'Catatan wajib diisi saat menolak pengajuan (FR-IZN-04).'];
    }
}
