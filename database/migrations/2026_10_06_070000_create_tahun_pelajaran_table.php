<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.2 — Tahun pelajaran (FR-TP-01). Status: draft | aktif | selesai (FR-TP-03). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tahun_pelajaran', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 9)->unique()->comment('format YYYY/YYYY, mis. 2026/2027');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('status', 10)->default('draft')->comment('draft | aktif | selesai');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahun_pelajaran');
    }
};
