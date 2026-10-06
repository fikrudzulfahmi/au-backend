<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pengaturan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pengaturan\InfoSekolahRequest;
use App\Http\Requests\Pengaturan\PengaturanSistemRequest;
use App\Http\Resources\SekolahResource;
use App\Models\Penandatangan;
use App\Models\ProfilSekolah;
use App\Services\AuditLogService;
use App\Services\BerkasService;
use App\Services\PengaturanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FR-SCH-01..06 — Info Sekolah (singleton) dan pengaturan sistem.
 * FR-SCH-06: hanya admin yang dapat mengubah; perubahan dicatat di audit_log.
 */
class PengaturanSekolahController extends Controller
{
    public function __construct(
        private readonly BerkasService $berkas,
        private readonly PengaturanService $pengaturan,
        private readonly AuditLogService $audit,
    ) {}

    public function show(): JsonResponse
    {
        $sekolah = ProfilSekolah::query()->first();

        if (! $sekolah) {
            return response()->json([
                'message' => 'Info sekolah belum diisi.',
                'code' => 'INFO_SEKOLAH_KOSONG',
            ], 404);
        }

        return response()->json(['data' => new SekolahResource($sekolah)]);
    }

    /** FR-SCH-04 — NPSN 8 digit, logo dikompres otomatis (KP-1.6). */
    public function update(InfoSekolahRequest $request): JsonResponse
    {
        $data = $request->safe()->except([
            'logo_kiri', 'logo_kanan', 'favicon', 'hero_foto',
        ]);

        $sekolah = ProfilSekolah::query()->first() ?? new ProfilSekolah;
        $lama = $sekolah->exists ? $sekolah->toArray() : null;

        // media_sosial datang bersarang; kosongkan nilai kosong agar konsisten.
        if (array_key_exists('media_sosial', $data)) {
            $data['media_sosial'] = array_filter(
                (array) $data['media_sosial'],
                fn ($v): bool => $v !== null && $v !== ''
            );
        }

        $unggahan = [
            'logo_kiri' => 'logo_kiri_path',
            'logo_kanan' => 'logo_kanan_path',
            'favicon' => 'favicon_path',
            'hero_foto' => 'hero_foto_path',
        ];

        try {
            foreach ($unggahan as $input => $kolom) {
                if (! $request->hasFile($input)) {
                    continue;
                }

                $pathBaru = $this->berkas->simpanGambarTerkompres(
                    $request->file($input),
                    'sekolah',
                    $input === 'favicon' ? 64 : 800,
                    $kolom
                );

                $this->berkas->hapus($sekolah->{$kolom});
                $data[$kolom] = $pathBaru;
            }

            DB::transaction(function () use ($sekolah, $data): void {
                if ($sekolah->exists) {
                    $sekolah->update($data);
                } else {
                    $sekolah->fill($data)->save();
                }
            });
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Gagal menyimpan Info Sekolah: '.$e->getMessage(),
                'code' => 'GAGAL_SIMPAN',
            ], 422);
        }

        $this->audit->catat(AuditLogService::AKSI_UBAH_INFO_SEKOLAH, $request->user(), $sekolah, $lama, [
            'nama_sekolah' => $sekolah->nama_sekolah,
            'npsn' => $sekolah->npsn,
        ]);

        return response()->json([
            'message' => 'Info Sekolah berhasil disimpan.',
            'data' => new SekolahResource($sekolah->refresh()),
        ]);
    }

    /**
     * FR-SCH-03 — menjadikan kepala sekolah sebagai penandatangan default
     * berdasarkan nama dan NIP pada Info Sekolah.
     */
    public function jadikanPenandatanganDefault(Request $request): JsonResponse
    {
        $sekolah = ProfilSekolah::query()->first();

        if (! $sekolah || ! $sekolah->nama_kepala_sekolah) {
            return response()->json([
                'message' => 'Isi nama kepala sekolah pada Info Sekolah terlebih dahulu.',
                'code' => 'DATA_KEPSEK_KOSONG',
            ], 422);
        }

        $penandatangan = Penandatangan::updateOrCreate(
            ['jabatan' => 'Kepala Sekolah'],
            [
                'nama' => $sekolah->nama_kepala_sekolah,
                'nip' => $sekolah->nip_kepala_sekolah,
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $penandatangan, null, [
            'nama' => $penandatangan->nama,
        ]);

        return response()->json([
            'message' => 'Kepala sekolah dijadikan penandatangan default.',
            'data' => ['id' => $penandatangan->id, 'nama' => $penandatangan->nama, 'nip' => $penandatangan->nip],
        ]);
    }

    /** FR-LOK-05 — pengaturan teknis presensi (akurasi GPS, kompresi foto). */
    public function sistem(): JsonResponse
    {
        return response()->json([
            'data' => [
                'gps_max_akurasi_m' => (int) $this->pengaturan->ambil('gps_max_akurasi_m'),
                'foto_max_sisi_px' => (int) $this->pengaturan->ambil('foto_max_sisi_px'),
                'foto_kualitas_jpeg' => (int) $this->pengaturan->ambil('foto_kualitas_jpeg'),
                'foto_target_maks_kb' => (int) $this->pengaturan->ambil('foto_target_maks_kb'),
            ],
        ]);
    }

    public function simpanSistem(PengaturanSistemRequest $request): JsonResponse
    {
        $data = $request->validated();

        foreach ($data as $kunci => $nilai) {
            $this->pengaturan->simpan($kunci, $nilai);
        }

        $this->audit->catat(AuditLogService::AKSI_UBAH_PENGATURAN, $request->user(), null, null, $data);

        return response()->json([
            'message' => 'Pengaturan sistem disimpan.',
            'data' => $data,
        ]);
    }
}
