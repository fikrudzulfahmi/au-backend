<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.4 — Jam kerja per jenis pegawai per hari (FR-LOK-04).
 * Menjadi acuan BR-15 (terlambat), BR-16 (pulang cepat), dan BR-24 (hari kerja).
 * Hari mengikuti penomoran ISO: 1 = Senin … 7 = Minggu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jam_kerja', function (Blueprint $table): void {
            $table->id();
            $table->enum('jenis_pegawai', ['guru', 'struktural']);
            $table->unsignedTinyInteger('hari')->comment('1 = Senin … 7 = Minggu');

            $table->boolean('is_hari_kerja')->default(true);
            $table->time('buka_presensi')->nullable()->comment('jam presensi masuk mulai dibuka');
            $table->time('jam_masuk')->nullable()->comment('batas masuk, tanpa toleransi (BR-15)');
            $table->time('jam_pulang')->nullable()->comment('batas pulang (BR-16)');

            $table->timestamps();

            // Satu jenis pegawai hanya punya satu aturan per hari.
            $table->unique(['jenis_pegawai', 'hari'], 'jam_kerja_jenis_hari_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jam_kerja');
    }
};
