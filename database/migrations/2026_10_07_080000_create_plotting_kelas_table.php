<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.2 — Plotting kelas / rombel siswa (FR-PLK).
 * Menempatkan siswa ke kelas per TAHUN PELAJARAN (berlaku kedua semester).
 *
 * Tanpa soft delete dengan sengaja: FR-PLK-06 membatalkan hasil naik kelas dengan
 * benar-benar menghapus baris plotting tujuan, dan indeks unik (tahun_pelajaran_id,
 * siswa_id) tetap terpakai bila baris hanya di-soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plotting_kelas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tahun_pelajaran_id')->constrained('tahun_pelajaran')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('status_akhir', ['berjalan', 'naik_kelas', 'tinggal_kelas', 'lulus', 'pindah', 'keluar'])
                ->default('berjalan');
            $table->foreignId('plotting_sebelumnya_id')->nullable()
                ->constrained('plotting_kelas')->cascadeOnUpdate()->nullOnDelete();
            $table->text('catatan')->nullable();
            $table->timestamps();

            // BR-04 — satu siswa hanya satu kelas pada satu tahun pelajaran.
            $table->unique(['tahun_pelajaran_id', 'siswa_id'], 'plotting_kelas_tp_siswa_uq');
            $table->index(['tahun_pelajaran_id', 'kelas_id'], 'plotting_kelas_tp_kelas_idx');
            $table->index('siswa_id', 'plotting_kelas_siswa_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plotting_kelas');
    }
};
