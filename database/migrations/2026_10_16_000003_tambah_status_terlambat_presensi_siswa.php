<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-23 — status presensi siswa ditambah `T` (Terlambat).
 *
 * Sekolah meminta satu status lagi: siswa yang datang setelah pelajaran dimulai.
 * Terlambat tetap dihitung sebagai kehadiran (lihat `PresensiSiswa::KEHADIRAN`),
 * tetapi dicatat tersendiri supaya kebiasaannya terlihat.
 *
 * Kolomnya enum, jadi menambah konstanta saja tidak cukup: database akan menolak
 * penyimpanan (SQLSTATE[01000] Data truncated) bila nilai di luar daftar. Migrasi
 * ini melebarkan enum tanpa menyentuh baris yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presensi_siswa', function (Blueprint $table): void {
            $table->enum('status', ['H', 'S', 'I', 'A', 'B', 'T'])
                ->default('H')
                ->change();
        });
    }

    public function down(): void
    {
        // Mengembalikan enum ke lima nilai akan menjadikan baris `T` tidak sah.
        // Baris itu dikembalikan menjadi hadir agar tidak ada data yang terpotong senyap.
        DB::table('presensi_siswa')->where('status', 'T')->update(['status' => 'H']);

        Schema::table('presensi_siswa', function (Blueprint $table): void {
            $table->enum('status', ['H', 'S', 'I', 'A', 'B'])
                ->default('H')
                ->change();
        });
    }
};
