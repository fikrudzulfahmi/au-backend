<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/** Bagian 2 — lima kode peran. */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Role::DAFTAR as $kode => $nama) {
            Role::updateOrCreate(['kode' => $kode], ['nama' => $nama]);
        }
    }
}
