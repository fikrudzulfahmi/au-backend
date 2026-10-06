<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

/** FR-TP-04 / BR-01 — mengaktifkan tahun pelajaran beserta satu semester. */
class AktifkanTahunPelajaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis_semester' => ['required', 'in:ganjil,genap'],
        ];
    }

    public function messages(): array
    {
        return [
            'jenis_semester.required' => 'Semester yang diaktifkan wajib dipilih.',
            'jenis_semester.in' => 'Semester harus ganjil atau genap.',
        ];
    }
}
