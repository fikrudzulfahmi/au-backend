<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.2 — Mutasi kelas dalam tahun berjalan (FR-PLK-05). Alasan wajib diisi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutasi_kelas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plotting_kelas_id')->constrained('plotting_kelas')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('kelas_asal_id')->constrained('kelas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kelas_tujuan_id')->constrained('kelas')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('tanggal');
            $table->text('alasan');
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['plotting_kelas_id', 'tanggal'], 'mutasi_kelas_plotting_tanggal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutasi_kelas');
    }
};
