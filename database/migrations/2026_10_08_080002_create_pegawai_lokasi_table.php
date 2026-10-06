<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.4 — Penetapan lokasi per pegawai (FR-LOK-03 / FR-PEG-04, FR-LOK-03 massal).
 * Pegawai tanpa baris di sini memakai lokasi default (BR-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pegawai_lokasi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('lokasi_id')->constrained('lokasi_presensi')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();

            // Satu lokasi tidak boleh ditetapkan dua kali untuk pegawai yang sama.
            $table->unique(['pegawai_id', 'lokasi_id'], 'pegawai_lokasi_uq');
            $table->index('lokasi_id', 'pegawai_lokasi_lokasi_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pegawai_lokasi');
    }
};
