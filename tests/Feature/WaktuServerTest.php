<?php

declare(strict_types=1);

use App\Services\WaktuService;

/**
 * BR-13 / BR-38 — GET /api/v1/waktu-server dipakai UI dan layar TV
 * untuk menyinkronkan jam dengan server.
 */
it('mengembalikan waktu server beserta zona dan offset', function (): void {
    $respons = $this->getJson('/api/v1/waktu-server');

    $respons->assertOk()
        ->assertJsonStructure(['data' => ['waktu', 'epoch_ms', 'zona', 'offset_menit']]);

    expect($respons->json('data.zona'))->toBe('Asia/Jakarta')
        ->and($respons->json('data.offset_menit'))->toBe(420);
});

it('memberi waktu dalam format ISO 8601 dengan offset zona', function (): void {
    $respons = $this->getJson('/api/v1/waktu-server');

    $waktu = $respons->json('data.waktu');

    expect($waktu)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/');
});

it('memakai waktu server sebagai acuan tunggal', function (): void {
    $payload = app(WaktuService::class)->payload();

    expect($payload['epoch_ms'])->toBeGreaterThan(0)
        ->and($payload['offset_menit'])->toBe(420);
});
