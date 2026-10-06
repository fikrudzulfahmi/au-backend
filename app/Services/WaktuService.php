<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * BR-13 / BR-38 — seluruh waktu presensi dan jam di UI memakai waktu server,
 * dengan zona `Asia/Jakarta` (dapat diubah lewat APP_TIMEZONE).
 */
class WaktuService
{
    public function sekarang(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'));
    }

    /** Payload untuk GET /api/v1/waktu-server (3.4). */
    public function payload(): array
    {
        $sekarang = $this->sekarang();

        return [
            'waktu' => $sekarang->toIso8601String(),
            'epoch_ms' => $sekarang->getTimestampMs(),
            'zona' => $sekarang->getTimezone()->getName(),
            'offset_menit' => $sekarang->getOffset() / 60,
        ];
    }
}
