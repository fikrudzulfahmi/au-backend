<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Services\ExcelService;
use Illuminate\Foundation\Http\FormRequest;

/** FR-SIS-03 / FR-PEG-03 — unggahan berkas import. */
class ImportMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $format = ExcelService::FORMAT_MASUKAN;

        return [
            'berkas' => [
                'required', 'file', 'max:4096',
                function (string $atribut, mixed $nilai, \Closure $gagal) use ($format): void {
                    $ekstensi = strtolower((string) $nilai?->getClientOriginalExtension());

                    if (! in_array($ekstensi, $format, true)) {
                        $gagal('Format berkas harus '.implode(', ', $format).'.');
                    }
                },
            ],
            'buat_akun' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'berkas.required' => 'Berkas import wajib dipilih.',
            'berkas.max' => 'Ukuran berkas maksimal 4 MB.',
        ];
    }
}
