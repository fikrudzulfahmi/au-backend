<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\JamKerja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-LOK-04 — jam kerja per jenis pegawai, satu pekan sekaligus. */
class JamKerjaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis_pegawai' => ['required', Rule::in(array_keys(JamKerja::DAFTAR_JENIS))],
            'per_hari' => ['required', 'array', 'min:1'],
            'per_hari.*.is_hari_kerja' => ['required', 'boolean'],
            'per_hari.*.buka_presensi' => ['nullable', 'date_format:H:i,H:i:s'],
            'per_hari.*.jam_masuk' => ['nullable', 'date_format:H:i,H:i:s'],
            'per_hari.*.jam_pulang' => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }

    public function attributes(): array
    {
        return ['jenis_pegawai' => 'Jenis pegawai', 'per_hari' => 'Aturan per hari'];
    }
}
