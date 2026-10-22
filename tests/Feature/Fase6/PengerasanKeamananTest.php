<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * 3.4/3.5 — pengerasan keamanan: negosiasi JSON untuk seluruh API dan
 * masa berlaku token yang terbatas.
 */
beforeEach(function (): void {
    siapkanPeran();
});

it('FR-SEC-06 menolak tamu dengan 401 JSON walau tanpa header Accept', function (): void {
    // Pemanggil non-peramban (curl, skrip) tidak mengirim Accept: application/json.
    $respons = $this->get('/api/v1/kelas');

    $respons->assertStatus(401)->assertJsonPath('code', 'TIDAK_TERAUTENTIKASI');
});

it('FR-SEC-06 menolak tamu dengan 401 JSON pada permintaan POST tanpa Accept', function (): void {
    $respons = $this->post('/api/v1/jurnal', []);

    $respons->assertStatus(401)->assertJsonPath('code', 'TIDAK_TERAUTENTIKASI');
});

it('FR-SEC-07 menolak token yang melewati masa berlaku 30 hari', function (): void {
    $user = buatPengguna([Role::ADMIN], ['username' => 'admin', 'password' => 'Rahasia#2026']);
    $token = $user->createToken('uji')->plainTextToken;

    // Token lama dibuat saat config expiration masih null; sekarang dibatasi 30 hari.
    DB::table('personal_access_tokens')->update(['created_at' => now()->subDays(31)]);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

it('FR-SEC-07 masih menerima token yang baru dibuat', function (): void {
    $user = buatPengguna([Role::ADMIN], ['username' => 'admin', 'password' => 'Rahasia#2026']);
    $token = $user->createToken('uji')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});
