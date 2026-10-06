<?php

declare(strict_types=1);

use App\Models\Pegawai;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Konfigurasi Pest (3.2)
|--------------------------------------------------------------------------
| Setiap uji fitur memakai RefreshDatabase pada database uji terpisah
| (`sipandu_test`, lihat phpunit.xml) sehingga data pengembangan tidak tersentuh.
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

/**
 * Membuat pegawai contoh (guru atau struktural) beserta akun login dan perannya.
 */
function buatPegawaiDenganAkun(
    string $jenis = Pegawai::JENIS_GURU,
    array $atributPegawai = [],
    array $peranTambahan = [],
): User {
    $pegawai = Pegawai::factory()->create(array_merge(['jenis_pegawai' => $jenis], $atributPegawai));

    $kode = $jenis === Pegawai::JENIS_GURU ? Role::GURU : Role::PEGAWAI_STRUKTURAL;

    /** @var User $user */
    $user = User::factory()->create([
        'pegawai_id' => $pegawai->id,
        'name' => $pegawai->nama,
        'username' => $pegawai->nip,
    ]);

    $user->roles()->sync(
        Role::query()->whereIn('kode', array_merge([$kode], $peranTambahan))->pluck('id')->all()
    );

    return $user->fresh(['roles', 'pegawai']);
}

/**
 * Pengguna tanpa data pegawai (mis. admin, FR-SEC-03).
 */
function buatPengguna(array $kodePeran = [Role::ADMIN], array $atribut = []): User
{
    /** @var User $user */
    $user = User::factory()->create($atribut);

    $user->roles()->sync(
        Role::query()->whereIn('kode', $kodePeran)->pluck('id')->all()
    );

    return $user->fresh(['roles', 'pegawai']);
}

/**
 * Mengisi seluruh peran bawaan (Bagian 2) pada database uji.
 */
function siapkanPeran(): void
{
    foreach (Role::DAFTAR as $kode => $nama) {
        Role::updateOrCreate(['kode' => $kode], ['nama' => $nama]);
    }
}
