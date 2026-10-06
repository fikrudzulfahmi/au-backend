<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.2 — Semester (FR-TP-02).
 * Setiap tahun pelajaran otomatis memiliki dua semester (ganjil, genap).
 * BR-01: hanya satu semester berstatus aktif pada satu waktu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_pelajaran_id')->constrained('tahun_pelajaran')->cascadeOnDelete();
            $table->string('jenis', 6)->comment('ganjil | genap');
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['tahun_pelajaran_id', 'jenis']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semester');
    }
};
