<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.2 — Siswa (FR-SIS-01).
 * Riwayat kelas per tahun disimpan pada `plotting_kelas` (Fase 2);
 * tabel ini hanya memuat identitas dan status siswa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siswa', function (Blueprint $table) {
            $table->id();
            $table->string('nis', 30)->unique();
            $table->string('nisn', 20)->nullable()->unique();
            $table->string('nama');
            $table->string('jenis_kelamin', 1)->comment('L | P');
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->year('tahun_masuk')->nullable();
            $table->string('status', 10)->default('aktif')->comment('aktif | lulus | pindah | keluar');
            $table->date('tanggal_status')->nullable();
            $table->year('tahun_lulus')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('nama');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siswa');
    }
};
