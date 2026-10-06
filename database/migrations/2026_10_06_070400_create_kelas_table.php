<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.2 — Kelas per tahun pelajaran (FR-KLS-02).
 * UQ (tahun_pelajaran_id, nama): kelas dengan nama sama di tahun berbeda adalah record berbeda.
 * UQ (tahun_pelajaran_id, wali_kelas_id): BR-02 — satu guru maksimal wali satu kelas per
 * tahun pelajaran. Kolom boleh NULL, dan MySQL/MariaDB mengizinkan banyak NULL pada indeks
 * unik, sehingga kelas tanpa wali tetap dapat dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_pelajaran_id')->constrained('tahun_pelajaran')->cascadeOnDelete();
            $table->string('nama');
            $table->string('tingkat', 3)->comment('X | XI | XII');
            $table->foreignId('jurusan_id')->constrained('jurusan')->restrictOnDelete();
            $table->foreignId('wali_kelas_id')->nullable()->constrained('pegawai')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tahun_pelajaran_id', 'nama']);
            $table->unique(['tahun_pelajaran_id', 'wali_kelas_id']);
            $table->index(['tahun_pelajaran_id', 'tingkat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelas');
    }
};
