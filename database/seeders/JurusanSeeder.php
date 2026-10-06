<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Jurusan;
use Illuminate\Database\Seeder;

/** Bagian 10 — 3 jurusan contoh (FR-KLS-01). */
class JurusanSeeder extends Seeder
{
    public const CONTOH = [
        ['TKJ', 'Teknik Komputer dan Jaringan'],
        ['RPL', 'Rekayasa Perangkat Lunak'],
        ['AKL', 'Akuntansi dan Keuangan Lembaga'],
    ];

    public function run(): void
    {
        foreach (self::CONTOH as [$kode, $nama]) {
            Jurusan::updateOrCreate(['kode' => $kode], ['nama' => $nama]);
        }
    }
}
