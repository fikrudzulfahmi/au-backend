<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

/** FR-TP-07 — hari libur dapat berupa satu tanggal atau rentang. */
class HariLiburRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tahun_pelajaran_id' => ['required', 'integer', 'exists:tahun_pelajaran,id'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string', 'max:191'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Bila hanya tanggal mulai yang diisi, jadikan satu hari.
        if ($this->filled('tanggal_mulai') && ! $this->filled('tanggal_selesai')) {
            $this->merge(['tanggal_selesai' => $this->input('tanggal_mulai')]);
        }
    }

    public function messages(): array
    {
        return [
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'keterangan.required' => 'Keterangan hari libur wajib diisi.',
        ];
    }
}
