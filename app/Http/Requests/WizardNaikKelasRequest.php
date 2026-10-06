<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PlottingKelas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-PLK-02 — pratinjau dan eksekusi wizard naik kelas / kelulusan. */
class WizardNaikKelasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $aturan = [
            'tahun_pelajaran_asal_id' => ['required', 'integer', 'exists:tahun_pelajaran,id'],
            'tahun_pelajaran_tujuan_id' => ['required', 'integer', 'different:tahun_pelajaran_asal_id', 'exists:tahun_pelajaran,id'],
            'kelas_asal_ids' => ['required', 'array', 'min:1'],
            'kelas_asal_ids.*' => ['integer', 'exists:kelas,id'],
        ];

        // Hanya diminta saat eksekusi, bukan saat pratinjau.
        if ($this->isMethod('post') && $this->routeIs('*wizard.eksekusi*')) {
            $aturan['keputusan'] = ['required', 'array', 'min:1'];
            $aturan['keputusan.*.siswa_id'] = ['required', 'integer', 'exists:siswa,id'];
            $aturan['keputusan.*.status_akhir'] = ['required', Rule::in(PlottingKelas::SELESAI)];
            $aturan['keputusan.*.kelas_tujuan_id'] = ['nullable', 'integer', 'exists:kelas,id'];
            $aturan['keputusan.*.catatan'] = ['nullable', 'string', 'max:500'];
        }

        return $aturan;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tahun_pelajaran_asal_id.required' => 'Tahun pelajaran asal wajib dipilih.',
            'tahun_pelajaran_tujuan_id.required' => 'Tahun pelajaran tujuan wajib dipilih.',
            'tahun_pelajaran_tujuan_id.different' => 'Tahun pelajaran asal dan tujuan tidak boleh sama.',
            'kelas_asal_ids.required' => 'Pilih minimal satu kelas asal.',
            'keputusan.required' => 'Belum ada keputusan yang dikirim.',
            'keputusan.*.status_akhir.in' => 'Status akhir harus naik_kelas, tinggal_kelas, lulus, pindah, atau keluar.',
        ];
    }
}
