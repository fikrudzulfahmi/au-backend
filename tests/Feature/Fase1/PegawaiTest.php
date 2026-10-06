<?php

declare(strict_types=1);

use App\Models\Pegawai;
use App\Models\PerangkatPengguna;
use App\Models\Role;
use App\Models\User;

beforeEach(function (): void {
    siapkanPeran();
});

/** KP-1.4 — pembuatan pegawai membuat akun dan peran sesuai jenis_pegawai. */
it('membuat akun dan peran guru saat pegawai guru dibuat', function (): void {
    $admin = sebagaiAdmin();

    $respons = $this->actingAs($admin)->postJson('/api/v1/pegawai', [
        'nip' => '197801012006041002',
        'nama' => 'Ahmad Fauzi, S.Pd.',
        'jenis_kelamin' => 'L',
        'jenis_pegawai' => Pegawai::JENIS_GURU,
        'jabatan' => 'Guru TKJ',
        'status_kepegawaian' => 'PNS',
        'buat_akun' => true,
    ]);

    $respons->assertCreated()->assertJsonPath('data.akun.peran', [Role::GURU]);

    $passwordAwal = $respons->json('password_awal');
    expect($passwordAwal)->toBeString()->toHaveLength(12);

    $user = User::where('username', '197801012006041002')->first();
    expect($user)->not->toBeNull()
        ->and($user->wajib_ganti_password)->toBeTrue()
        ->and($user->punyaPeran(Role::GURU))->toBeTrue()
        ->and($user->pegawai_id)->toBe(Pegawai::first()->id);

    // Password awal yang dilaporkan benar-benar dapat dipakai untuk masuk.
    $this->postJson('/api/v1/auth/login', [
        'username' => '197801012006041002',
        'password' => $passwordAwal,
    ])->assertOk();
});

it('memberi peran pegawai_struktural untuk pegawai struktural', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/pegawai', [
        'nip' => '198706112012012006',
        'nama' => 'Endang Sulistyowati, S.Pd.',
        'jenis_kelamin' => 'P',
        'jenis_pegawai' => Pegawai::JENIS_STRUKTURAL,
        'jabatan' => 'Kepala Tata Usaha',
        'status_kepegawaian' => 'PNS',
    ])->assertCreated()->assertJsonPath('data.akun.peran', [Role::PEGAWAI_STRUKTURAL]);
});

it('dapat membuat pegawai tanpa akun login', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/pegawai', [
        'nip' => '199001102015041003',
        'nama' => 'Bagus Setiawan, S.Pd.',
        'jenis_kelamin' => 'L',
        'jenis_pegawai' => Pegawai::JENIS_GURU,
        'status_kepegawaian' => 'GTY',
        'buat_akun' => false,
    ])->assertCreated()->assertJsonPath('data.akun', null);

    expect(User::count())->toBe(1); // hanya admin
});

it('menyesuaikan peran saat jenis_pegawai diubah', function (): void {
    $admin = sebagaiAdmin();
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $user = User::factory()->create(['pegawai_id' => $pegawai->id, 'username' => $pegawai->nip]);
    $user->roles()->sync([Role::where('kode', Role::GURU)->value('id')]);

    $this->actingAs($admin)->putJson("/api/v1/pegawai/{$pegawai->id}", [
        'nip' => $pegawai->nip,
        'nama' => $pegawai->nama,
        'jenis_kelamin' => $pegawai->jenis_kelamin,
        'jenis_pegawai' => Pegawai::JENIS_STRUKTURAL,
        'status_kepegawaian' => 'PNS',
    ])->assertOk();

    expect($user->refresh()->kodePeran())->toBe([Role::PEGAWAI_STRUKTURAL]);
});

it('menolak NIP yang sudah terdaftar', function (): void {
    $admin = sebagaiAdmin();
    Pegawai::factory()->create(['nip' => '1234567890']);

    $this->actingAs($admin)->postJson('/api/v1/pegawai', [
        'nip' => '1234567890',
        'nama' => 'Pegawai Ganda',
        'jenis_kelamin' => 'L',
        'jenis_pegawai' => Pegawai::JENIS_GURU,
        'status_kepegawaian' => 'Honorer',
    ])->assertStatus(422)->assertJsonPath('errors.nip.0', 'NIP tersebut sudah terdaftar.');
});

/** FR-PEG-06 — reset password oleh admin. */
it('mereset password dan mencabut token lama', function (): void {
    $admin = sebagaiAdmin();
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $user = User::factory()->create([
        'pegawai_id' => $pegawai->id, 'username' => $pegawai->nip, 'password' => 'Lama#2026',
    ]);
    $user->createToken('uji');

    $respons = $this->actingAs($admin)->postJson("/api/v1/pegawai/{$pegawai->id}/reset-password");

    $respons->assertOk();
    $passwordBaru = $respons->json('data.password_baru');

    expect($passwordBaru)->toBeString()->toHaveLength(12)
        ->and($user->refresh()->wajib_ganti_password)->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    $this->postJson('/api/v1/auth/login', ['username' => $pegawai->nip, 'password' => $passwordBaru])
        ->assertOk();
});

/** FR-PEG-05 / BR-14 — reset perangkat terdaftar. */
it('mengosongkan perangkat terdaftar milik pengguna', function (): void {
    $admin = sebagaiAdmin();
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $user = User::factory()->create(['pegawai_id' => $pegawai->id, 'username' => $pegawai->nip]);

    PerangkatPengguna::create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'token-perangkat'),
        'user_agent' => 'Peramban Uji',
        'terdaftar_pada' => now(),
    ]);

    $this->actingAs($admin)->postJson("/api/v1/pegawai/{$pegawai->id}/reset-perangkat")->assertOk();

    expect(PerangkatPengguna::where('user_id', $user->id)->count())->toBe(0);
    $this->assertDatabaseHas('audit_log', ['aksi' => 'reset_perangkat']);
});

it('menolak reset password untuk pegawai tanpa akun', function (): void {
    $admin = sebagaiAdmin();
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $this->actingAs($admin)
        ->postJson("/api/v1/pegawai/{$pegawai->id}/reset-password")
        ->assertStatus(409)
        ->assertJsonPath('code', 'TANPA_AKUN');
});

it('tidak pernah mengirim password atau hash pada daftar pegawai', function (): void {
    $admin = sebagaiAdmin();
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    User::factory()->create(['pegawai_id' => $pegawai->id, 'username' => $pegawai->nip, 'password' => 'Rahasia#2026']);

    $respons = $this->actingAs($admin)->getJson('/api/v1/pegawai');

    $respons->assertOk();

    // Telusuri seluruh kunci JSON: tidak boleh ada kunci bernama tepat "password".
    $kunci = [];
    $telusuri = function (array $data) use (&$kunci, &$telusuri): void {
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                $kunci[] = $k;
            }
            if (is_array($v)) {
                $telusuri($v);
            }
        }
    };
    $telusuri($respons->json());

    expect($kunci)->not->toContain('password')
        ->and($kunci)->not->toContain('remember_token');

    // Hash bcrypt tidak boleh ikut terkirim.
    expect(json_encode($respons->json(), JSON_THROW_ON_ERROR))->not->toContain('$2y$');
});
