<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-23 — status presensi siswa ditambah `B` (Bolos).
 *
 * Kolomnya enum, jadi menambah konstanta saja tidak cukup: database akan
 * menolak penyimpanan (SQLSTATE[01000] Data truncated) bila nilai di luar
 * daftar. Migrasi ini melebarkan enum tanpa menyentuh baris yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presensi_siswa', function (Blueprint $table): void {
            $table->enum('status', ['H', 'S', 'I', 'A', 'B'])
                ->default('H')
                ->change();
        });
    }

    public function down(): void
    {
        // Mengembalikan enum ke empat nilai akan menjadikan baris `B` tidak sah;
        // konversikan dulu agar tidak ada data yang terpotong senyap.
        Schema::table('presensi_siswa', function (Blueprint $table): void {
            $table->enum('status', ['H', 'S', 'I', 'A'])
                ->default('H')
                ->change();
        });
    }
};
