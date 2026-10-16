<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan guru piket (permintaan pengguna: "menu penugasan guru piket, dan
 * di piketkan hari apa").
 *
 * `hari` memakai penomoran ISO-8601 yang sama dengan `jadwal` (1 = Senin ..
 * 7 = Minggu) supaya "siapa piket hari ini" tidak perlu penerjemahan hari.
 * Penugasan berlaku per semester, mengikuti `jadwal`: rotasi piket disusun
 * bersama jadwal pelajaran dan gugur sendiri saat semester berganti.
 *
 * Dua indeksnya menegakkan hal yang berbeda:
 *  - unik (semester, pegawai, hari): satu guru tidak ditugaskan dua kali pada
 *    hari yang sama — penugasan ganda membuat "penggantinya siapa" ambigu;
 *  - index (semester, hari): jalur baca yang sebenarnya dipakai setiap kali
 *    guru membuka halaman isi jurnal ("apakah saya piket hari ini?").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penugasan_piket', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedTinyInteger('hari')->comment('1 = Senin .. 7 = Minggu (ISO-8601)');
            $table->string('catatan', 120)->nullable()->comment('mis. "piket ruang guru", opsional');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['semester_id', 'pegawai_id', 'hari'], 'penugasan_piket_smt_guru_hari_uq');
            $table->index(['semester_id', 'hari'], 'penugasan_piket_smt_hari_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penugasan_piket');
    }
};
