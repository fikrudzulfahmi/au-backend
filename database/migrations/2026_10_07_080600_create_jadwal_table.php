<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.3 — Jadwal pelajaran (FR-JDW).
 *
 * `pegawai_id` dan `kelas_id` sengaja didenormalisasi dari plotting_mapel (7.3 catatan)
 * agar bentrok guru (BR-06) dan bentrok kelas (BR-07) dapat ditegakkan database, bukan
 * hanya di kode aplikasi. Keduanya diisi sistem saat menyimpan, tidak diedit langsung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedTinyInteger('hari');
            $table->foreignId('slot_jam_id')->constrained('slot_jam')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('plotting_mapel_id')->constrained('plotting_mapel')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            // BR-06 — guru tidak boleh punya dua jadwal pada (semester, hari, slot) yang sama.
            $table->unique(['semester_id', 'hari', 'slot_jam_id', 'pegawai_id'], 'jadwal_smt_hari_slot_guru_uq');
            // BR-07 — kelas tidak boleh punya dua jadwal pada (semester, hari, slot) yang sama.
            $table->unique(['semester_id', 'hari', 'slot_jam_id', 'kelas_id'], 'jadwal_smt_hari_slot_kelas_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal');
    }
};
