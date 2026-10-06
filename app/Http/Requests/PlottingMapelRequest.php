<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-PLM-01..03 — plotting guru pengampu. */
class PlottingMapelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $wajib = $this->isMethod('put') || $this->isMethod('patch') || $this->isMethod('post');

        return [
            'semester_id' => $this->isMethod('post') ? ['required', 'integer', 'exists:semester,id'] : ['sometimes', 'integer', 'exists:semester,id'],
            'pegawai_id' => $wajib ? ['required', 'integer', 'exists:pegawai,id'] : ['sometimes', 'integer'],
            'mapel_id' => $this->isMethod('post') ? ['required', 'integer', 'exists:mapel,id'] : ['sometimes', 'integer', 'exists:mapel,id'],
            'kelas_id' => $this->isMethod('post') ? ['required', 'integer', 'exists:kelas,id'] : ['sometimes', 'integer', 'exists:kelas,id'],
            'jp_per_minggu' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'semester_id.required' => 'Semester wajib dipilih.',
            'pegawai_id.required' => 'Guru pengampu wajib dipilih.',
            'mapel_id.required' => 'Mata pelajaran wajib dipilih.',
            'kelas_id.required' => 'Kelas wajib dipilih.',
            'jp_per_minggu.required' => 'JP per minggu wajib diisi.',
            'jp_per_minggu.min' => 'JP per minggu minimal 1.',
            'jp_per_minggu.max' => 'JP per minggu maksimal 20.',
        ];
    }
}
