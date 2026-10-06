<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    siapkanPeran();
});

it('berhasil masuk dengan username dan password yang benar', function (): void {
    $user = buatPengguna([Role::ADMIN], [
        'username' => 'admin',
        'password' => 'Rahasia#2026',
    ]);

    $respons = $this->postJson('/api/v1/auth/login', [
        'username' => 'admin',
        'password' => 'Rahasia#2026',
    ]);

    $respons->assertOk()
        ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id', 'username', 'nama', 'peran']]]);

    expect($respons->json('data.token_type'))->toBe('Bearer')
        ->and($respons->json('data.user.username'))->toBe('admin')
        ->and($respons->json('data.user.peran'))->toBe([Role::ADMIN]);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('menolak password yang salah dengan pesan Bahasa Indonesia', function (): void {
    buatPengguna([Role::ADMIN], ['username' => 'admin', 'password' => 'Rahasia#2026']);

    $respons = $this->postJson('/api/v1/auth/login', [
        'username' => 'admin',
        'password' => 'salah',
    ]);

    $respons->assertStatus(401)
        ->assertJsonPath('code', 'KREDENSIAL_SALAH')
        ->assertJsonPath('message', 'Username atau password salah.');
});

it('mencatat login gagal pada audit_log (FR-SEC-05)', function (): void {
    buatPengguna([Role::ADMIN], ['username' => 'admin', 'password' => 'Rahasia#2026']);

    $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'salah']);

    expect(AuditLog::where('aksi', AuditLogService::AKSI_LOGIN_GAGAL)->count())->toBe(1);
});

it('menolak akun yang tidak aktif', function (): void {
    buatPengguna([Role::ADMIN], [
        'username' => 'nonaktif',
        'password' => 'Rahasia#2026',
        'is_active' => false,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'username' => 'nonaktif',
        'password' => 'Rahasia#2026',
    ])->assertStatus(403)->assertJsonPath('code', 'AKUN_TIDAK_AKTIF');
});

it('membatasi percobaan login menjadi lima per menit per IP (FR-SEC-01)', function (): void {
    buatPengguna([Role::ADMIN], ['username' => 'admin', 'password' => 'Rahasia#2026']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'salah'])
            ->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'salah'])
        ->assertStatus(429);
});

it('menyimpan password dalam bentuk hash', function (): void {
    $user = buatPengguna([Role::ADMIN], ['username' => 'admin', 'password' => 'Rahasia#2026']);

    expect($user->password)->not->toBe('Rahasia#2026')
        ->and(Hash::check('Rahasia#2026', $user->password))->toBeTrue();
});

it('mengembalikan data pengguna pada /auth/me', function (): void {
    $user = buatPegawaiDenganAkun();

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.username', $user->username)
        ->assertJsonPath('data.jenis_pegawai', 'guru');
});

it('menolak /auth/me tanpa token', function (): void {
    $this->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'TIDAK_TERAUTENTIKASI');
});

it('mencabut token saat logout', function (): void {
    $user = buatPengguna([Role::ADMIN], ['username' => 'admin', 'password' => 'Rahasia#2026']);

    $token = $this->postJson('/api/v1/auth/login', [
        'username' => 'admin',
        'password' => 'Rahasia#2026',
    ])->json('data.token');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect(User::find($user->id)->tokens()->count())->toBe(0);
});
