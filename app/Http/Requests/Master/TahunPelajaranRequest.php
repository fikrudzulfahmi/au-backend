<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-TP-01 — nama tahun pelajaran wajib berformat YYYY/YYYY. */
class TahunPelajaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // otorisasi peran ditangani middleware `peran`
    }

    public function rules(): array
    {
        $id = $this->route('tahun_pelajaran')?->getKey();

        return [
            'nama' => [
                'required', 'string', 'regex:/^\d{4}\/\d{4}$/',
                Rule::unique('tahun_pelajaran', 'nama')->ignore($id)->withoutTrashed(),
            ],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after:tanggal_mulai'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama.regex' => 'Nama tahun pelajaran harus berformat YYYY/YYYY, misalnya 2026/2027.',
            'nama.unique' => 'Tahun pelajaran dengan nama tersebut sudah ada.',
            'tanggal_selesai.after' => 'Tanggal selesai harus setelah tanggal mulai.',
        ];
    }
}
