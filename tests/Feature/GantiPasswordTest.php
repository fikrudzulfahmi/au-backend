<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Role;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    siapkanPeran();
});

it('menolak akses tanpa autentikasi', function (): void {
    $this->postJson('/api/v1/auth/ganti-password', [
        'password_lama' => 'apa saja',
        'password' => 'Baru#2026',
        'password_confirmation' => 'Baru#2026',
    ])->assertStatus(401);
});

it('menolak bila password lama tidak sesuai', function (): void {
    $user = buatPengguna([Role::ADMIN], ['password' => 'Lama#2026']);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/ganti-password', [
            'password_lama' => 'salah',
            'password' => 'Baru#2026',
            'password_confirmation' => 'Baru#2026',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.password_lama.0', 'Password lama tidak sesuai.');
});

it('menolak konfirmasi password yang tidak sama', function (): void {
    $user = buatPengguna([Role::ADMIN], ['password' => 'Lama#2026']);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/ganti-password', [
            'password_lama' => 'Lama#2026',
            'password' => 'Baru#2026',
            'password_confirmation' => 'Beda#2026',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'Konfirmasi password tidak sama.');
});

it('menolak password baru yang kurang dari delapan karakter', function (): void {
    $user = buatPengguna([Role::ADMIN], ['password' => 'Lama#2026']);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/ganti-password', [
            'password_lama' => 'Lama#2026',
            'password' => 'pendek',
            'password_confirmation' => 'pendek',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'Password baru minimal 8 karakter.');
});

it('mengganti password dan melepas kewajiban ganti password (FR-SEC-04)', function (): void {
    $user = buatPengguna([Role::ADMIN], [
        'password' => 'Lama#2026',
        'wajib_ganti_password' => true,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/ganti-password', [
            'password_lama' => 'Lama#2026',
            'password' => 'Baru#2026',
            'password_confirmation' => 'Baru#2026',
        ])
        ->assertOk()
        ->assertJsonPath('data.wajib_ganti_password', false);

    $user->refresh();

    expect(Hash::check('Baru#2026', $user->password))->toBeTrue()
        ->and($user->wajib_ganti_password)->toBeFalse();

    expect(AuditLog::where('aksi', AuditLogService::AKSI_GANTI_PASSWORD)->count())->toBe(1);
});
