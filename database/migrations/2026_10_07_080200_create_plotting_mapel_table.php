<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.2 — Plotting mapel / guru pengampu per kelas per semester (FR-PLM). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plotting_mapel', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedTinyInteger('jp_per_minggu');
            $table->timestamps();

            // BR-03 — satu guru pengampu per (semester, mapel, kelas).
            $table->unique(['semester_id', 'mapel_id', 'kelas_id'], 'plotting_mapel_smt_mapel_kelas_uq');
            $table->index(['pegawai_id', 'semester_id'], 'plotting_mapel_pegawai_smt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plotting_mapel');
    }
};
