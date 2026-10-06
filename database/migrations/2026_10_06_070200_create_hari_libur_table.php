<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.2 — Hari libur per tahun pelajaran (FR-TP-07).
 * Dipakai menghitung hari kerja dan alpa (BR-24). Dapat berupa rentang tanggal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hari_libur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_pelajaran_id')->constrained('tahun_pelajaran')->cascadeOnDelete();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('keterangan');
            $table->timestamps();

            $table->index(['tahun_pelajaran_id', 'tanggal_mulai', 'tanggal_selesai'], 'hari_libur_rentang_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hari_libur');
    }
};
