<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PengajuanIzin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-IZN-01 — pengajuan izin/sakit/dinas/cuti. */
class PengajuanIzinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis' => ['required', Rule::in(array_keys(PengajuanIzin::DAFTAR_JENIS))],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'min:5', 'max:1000'],
            'lampiran' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:2048'],
            'presensi_luar_radius' => ['sometimes', 'boolean'],
        ];
    }

    /** FR-IZN-02 — kotak "Presensi dari luar radius" hanya berlaku untuk dinas. */
    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            if ($this->input('presensi_luar_radius') && $this->input('jenis') !== PengajuanIzin::JENIS_DINAS) {
                $v->errors()->add('presensi_luar_radius', 'Opsi presensi luar radius hanya berlaku untuk pengajuan dinas (FR-IZN-02).');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'jenis' => 'Jenis pengajuan',
            'tanggal_mulai' => 'Tanggal mulai',
            'tanggal_selesai' => 'Tanggal selesai',
            'alasan' => 'Alasan',
            'lampiran' => 'Lampiran',
        ];
    }
}
