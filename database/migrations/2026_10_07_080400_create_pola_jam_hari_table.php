<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.3 — Hari yang berlaku pada sebuah pola jam. 1=Senin … 7=Minggu (FR-JAM-01). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pola_jam_hari', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pola_jam_id')->constrained('pola_jam')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedTinyInteger('hari');
            $table->timestamps();

            // BR-05 — satu hari hanya masuk satu pola jam dalam satu semester.
            $table->unique(['semester_id', 'hari'], 'pola_jam_hari_smt_hari_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pola_jam_hari');
    }
};
