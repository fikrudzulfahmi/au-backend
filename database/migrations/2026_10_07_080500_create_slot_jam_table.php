<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.3 — Slot jam pada sebuah pola (FR-JAM-02/03). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_jam', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pola_jam_id')->constrained('pola_jam')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan');
            $table->enum('tipe', ['pelajaran', 'istirahat', 'kegiatan']);
            $table->string('label', 100);
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            // Wajib untuk tipe pelajaran; NULL untuk istirahat/kegiatan. Indeks unik MySQL
            // memperbolehkan banyak NULL, sehingga istirahat tidak saling menabrak.
            $table->unsignedSmallInteger('jam_ke')->nullable();
            $table->timestamps();

            $table->unique(['pola_jam_id', 'urutan'], 'slot_jam_pola_urutan_uq');
            $table->unique(['pola_jam_id', 'jam_ke'], 'slot_jam_pola_jamke_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_jam');
    }
};
