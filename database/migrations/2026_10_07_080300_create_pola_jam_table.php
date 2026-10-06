<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.3 — Pola jam per semester, mis. "Senin-Kamis" dan "Jumat" (FR-JAM-01). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pola_jam', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('nama', 100);
            $table->timestamps();

            $table->unique(['semester_id', 'nama'], 'pola_jam_smt_nama_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pola_jam');
    }
};
