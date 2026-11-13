<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PresensiPegawai;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * FR-PRS-13 — koreksi manual presensi oleh admin, wajib beralasan.
 *
 * Kedua sisi (datang & pulang) boleh dikoreksi. Klien cukup mengirim `HH:MM`
 * karena baris presensi sudah terikat satu tanggal; jam itu dinormalkan menjadi
 * datetime penuh bertanggal presensi supaya tidak tersimpan sebagai tanggal lain.
 */
class KoreksiPresensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $presensi = $this->presensi();
        $tanggal = $presensi?->tanggal?->toDateString();

        foreach (['masuk_waktu', 'pulang_waktu'] as $bidang) {
            $nilai = $this->input($bidang);

            if (! is_string($nilai)) {
                continue;
            }

            $nilai = trim($nilai);

            if ($nilai === '') {
                $this->merge([$bidang => null]);

                continue;
            }

            // "07:15" / "07:15:00" → "<tanggal presensi> 07:15:00".
            if ($tanggal !== null && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $nilai) === 1) {
                $this->merge([$bidang => $tanggal.' '.$nilai]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
            'masuk_waktu' => ['nullable', 'date'],
            'pulang_waktu' => ['nullable', 'date'],
            'masuk_status' => ['nullable', 'in:hadir,terlambat'],
            'pulang_status' => ['nullable', 'in:normal,pulang_cepat'],
            'masuk_validasi' => ['nullable', 'in:valid,menunggu,disetujui,ditolak'],
            'pulang_validasi' => ['nullable', 'in:valid,menunggu,disetujui,ditolak'],
            'catatan_penyetuju' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Koreksi hanya berlaku untuk tanggal presensi itu sendiri dan urutan
     * masuk → pulang harus tetap wajar; tanpa ini, jam dari hari lain atau
     * jam pulang sebelum jam masuk akan merusak hitungan menit di laporan.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $presensi = $this->presensi();
            $tanggal = $presensi?->tanggal?->toDateString();

            foreach (['masuk_waktu', 'pulang_waktu'] as $bidang) {
                $nilai = $this->input($bidang);

                if (! is_string($nilai) || $nilai === '' || $tanggal === null) {
                    continue;
                }

                if (Carbon::parse($nilai)->toDateString() !== $tanggal) {
                    $v->errors()->add(
                        $bidang,
                        'Jam koreksi harus berada pada tanggal presensi ('.$tanggal.').',
                    );
                }
            }

            // Nilai lama bisa berupa objek tanggal (cast model), bukan string:
            // keduanya harus bisa dibandingkan agar koreksi satu sisi tetap
            // diperiksa terhadap sisi seberangnya yang tidak dikirim.
            $masuk = $this->waktuPembanding('masuk_waktu', $presensi?->masuk_waktu);
            $pulang = $this->waktuPembanding('pulang_waktu', $presensi?->pulang_waktu);

            if ($masuk !== null && $pulang !== null && $pulang->lessThan($masuk)) {
                $v->errors()->add('pulang_waktu', 'Jam pulang tidak boleh lebih awal dari jam masuk.');
            }

            // Baris tanpa jam masuk tidak boleh hanya diberi jam pulang: presensi
            // pulang selalu mensyaratkan presensi masuk (BR-10).
            if ($this->isPulangBaru() && $masuk === null) {
                $v->errors()->add('masuk_waktu', 'Isi jam masuk terlebih dahulu sebelum mengoreksi jam pulang.');
            }
        });
    }

    private function isPulangBaru(): bool
    {
        $nilai = $this->input('pulang_waktu');

        return is_string($nilai) && trim($nilai) !== '';
    }

    private function waktuPembanding(string $bidang, mixed $lama): ?Carbon
    {
        $nilai = $this->input($bidang) ?? $lama;

        if ($nilai instanceof \DateTimeInterface) {
            return Carbon::instance($nilai);
        }

        return is_string($nilai) && trim($nilai) !== '' ? Carbon::parse($nilai) : null;
    }

    public function attributes(): array
    {
        return [
            'alasan' => 'Alasan koreksi',
            'masuk_waktu' => 'Jam masuk',
            'pulang_waktu' => 'Jam pulang',
            'masuk_status' => 'Status masuk',
            'pulang_status' => 'Status pulang',
        ];
    }

    private function presensi(): ?PresensiPegawai
    {
        $presensi = $this->route('presensi');

        return $presensi instanceof PresensiPegawai ? $presensi : null;
    }
}
