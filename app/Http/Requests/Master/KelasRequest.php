<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Models\Kelas;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-KLS-02 + BR-02 — kelas per tahun pelajaran.
 * BR-02 (mengikat): satu guru hanya boleh menjadi wali kelas satu kelas pada
 * satu tahun pelajaran. Ditegakkan di lapisan validasi (pesan Bahasa Indonesia)
 * DAN di indeks unik basis data, agar tidak dapat dilewati lewat API apa pun.
 */
class KelasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('kelas')?->getKey();
        $tahunId = $this->input('tahun_pelajaran_id');

        return [
            'tahun_pelajaran_id' => ['required', 'integer', 'exists:tahun_pelajaran,id'],
            'nama' => [
                'required', 'string', 'max:191',
                Rule::unique('kelas', 'nama')
                    ->where(fn ($q) => $q->where('tahun_pelajaran_id', $tahunId))
                    ->withoutTrashed()
                    ->ignore($id),
            ],
            'tingkat' => ['required', Rule::in(Kelas::TINGKAT)],
            'jurusan_id' => ['required', 'integer', 'exists:jurusan,id'],
            // FR-PLM-03 / 5.2 — wali kelas adalah guru (pegawai jenis_pegawai = guru).
            'wali_kelas_id' => [
                'nullable', 'integer',
                Rule::exists('pegawai', 'id')
                    ->where(fn ($q) => $q->where('jenis_pegawai', 'guru')->whereNull('deleted_at')),
            ],
            'is_active' => ['boolean'],
        ];
    }

    /** BR-02 — pemeriksaan silang dengan pesan yang jelas. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $waliId = $this->input('wali_kelas_id');
                $tahunId = $this->input('tahun_pelajaran_id');
                $id = $this->route('kelas')?->getKey();

                if (! $waliId || ! $tahunId) {
                    return;
                }

                $sudahAda = Kelas::query()
                    ->where('tahun_pelajaran_id', $tahunId)
                    ->where('wali_kelas_id', $waliId)
                    ->when($id, fn ($q) => $q->whereKeyNot($id))
                    ->first();

                if ($sudahAda) {
                    $validator->errors()->add(
                        'wali_kelas_id',
                        "Guru ini sudah menjadi wali kelas {$sudahAda->nama} pada tahun pelajaran yang sama (BR-02)."
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Nama kelas sudah dipakai pada tahun pelajaran ini.',
            'wali_kelas_id.exists' => 'Wali kelas harus dipilih dari pegawai berjenis guru.',
            'tingkat.in' => 'Tingkat kelas harus X, XI, atau XII.',
        ];
    }
}
