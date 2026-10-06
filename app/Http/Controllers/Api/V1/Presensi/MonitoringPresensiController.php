<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Presensi;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\KoreksiPresensiRequest;
use App\Http\Requests\PutuskanPresensiRequest;
use App\Http\Resources\PresensiResource;
use App\Models\PresensiPegawai;
use App\Services\MonitoringPresensiService;
use App\Services\PresensiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-PRS-10/11/13 — monitoring harian, antrean persetujuan luar radius,
 * dan koreksi manual. Akses: admin/kepsek (K), wakasek (lihat).
 */
final class MonitoringPresensiController extends Controller
{
    public function __construct(
        private readonly MonitoringPresensiService $monitoring,
        private readonly PresensiService $presensi,
    ) {}

    /** FR-PRS-10 — daftar seluruh pegawai aktif beserta status hari itu. */
    public function harian(Request $request): JsonResponse
    {
        $tanggal = $request->date('tanggal')?->toDateString() ?? now()->toDateString();

        return response()->json([
            'data' => $this->monitoring->harian(
                $tanggal,
                $request->filled('jenis_pegawai') ? (string) $request->string('jenis_pegawai') : null,
                $request->filled('status') ? (string) $request->string('status') : null,
            ),
            'meta' => ['kategori' => MonitoringPresensiService::DAFTAR_STATUS],
        ]);
    }

    /** Rincian satu presensi beserta foto dan koordinatnya. */
    public function detail(PresensiPegawai $presensi): JsonResponse
    {
        return response()->json([
            'data' => new PresensiResource($presensi->load(['pegawai', 'masukLokasi', 'pulangLokasi', 'diputuskanOleh'])),
        ]);
    }

    /** FR-PRS-11 — antrean presensi luar radius yang menunggu keputusan. */
    public function antrean(Request $request): JsonResponse
    {
        $tanggal = $request->date('tanggal')?->toDateString();

        $baris = $this->monitoring->antreanPersetujuan($tanggal);

        return response()->json([
            'data' => array_map(fn (array $b): array => [
                'presensi_id' => $b['id'],
                'pegawai_id' => $b['pegawai_id'],
                'pegawai' => $b['pegawai']['nama'] ?? null,
                'nip' => $b['pegawai']['nip'] ?? null,
                'tanggal' => $b['tanggal'],
                'masuk_jam' => $b['masuk_waktu'] !== null ? substr((string) $b['masuk_waktu'], 11, 5) : null,
                'masuk_lat' => $b['masuk_lat'],
                'masuk_lng' => $b['masuk_lng'],
                'masuk_jarak_m' => $b['masuk_jarak_m'],
                'masuk_validasi' => $b['masuk_validasi'],
                'masuk_alasan_luar_radius' => $b['masuk_alasan_luar_radius'],
                'pulang_validasi' => $b['pulang_validasi'],
                'pulang_alasan_luar_radius' => $b['pulang_alasan_luar_radius'],
            ], $baris),
        ]);
    }

    /** FR-PRS-11 — memutuskan satu presensi luar radius. */
    public function putuskan(PutuskanPresensiRequest $request, PresensiPegawai $presensi): JsonResponse
    {
        $presensi = $this->presensi->putuskan(
            $presensi,
            $request->validated('keputusan'),
            $request->validated('catatan_penyetuju'),
            $request->user(),
            (string) ($request->validated('sisi') ?? 'masuk'),
        );

        return response()->json([
            'message' => 'Keputusan tersimpan.',
            'data' => new PresensiResource($presensi),
        ]);
    }

    /** FR-PRS-11 — aksi massal untuk antrean persetujuan. */
    public function putuskanMassal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'array', 'min:1'],
            'id.*' => ['integer', 'exists:presensi_pegawai,id'],
            'keputusan' => ['required', 'in:disetujui,ditolak'],
            'sisi' => ['sometimes', 'in:masuk,pulang'],
            'catatan_penyetuju' => ['nullable', 'string', 'max:500'],
        ]);

        $berhasil = 0;

        foreach ($data['id'] as $id) {
            $presensi = PresensiPegawai::find($id);

            if ($presensi === null) {
                continue;
            }

            try {
                $this->presensi->putuskan(
                    $presensi,
                    $data['keputusan'],
                    $data['catatan_penyetuju'] ?? null,
                    $request->user(),
                    $data['sisi'] ?? 'masuk',
                );
                $berhasil++;
            } catch (AturanBisnisException) {
                // Baris yang tidak lagi menunggu keputusan dilewati, bukan menggagalkan
                // seluruh aksi massal.
                continue;
            }
        }

        return response()->json([
            'message' => "Keputusan tersimpan untuk {$berhasil} presensi.",
            'data' => ['berhasil' => $berhasil, 'diminta' => count($data['id'])],
        ]);
    }

    /** FR-PRS-13 — koreksi manual presensi, wajib beralasan. */
    public function koreksi(KoreksiPresensiRequest $request, PresensiPegawai $presensi): JsonResponse
    {
        $data = $request->validated();
        $alasan = $data['alasan'];
        unset($data['alasan']);

        $presensi = $this->presensi->koreksi($presensi, $data, $request->user(), $alasan);

        return response()->json([
            'message' => 'Koreksi presensi tersimpan dan tercatat di audit log.',
            'data' => new PresensiResource($presensi->load(['pegawai', 'masukLokasi', 'pulangLokasi'])),
        ]);
    }
}
