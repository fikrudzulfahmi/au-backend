<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.5 — Presensi siswa per sesi jurnal (FR-JRN-03, BR-22, BR-23).
 *
 * Daftar siswa ditentukan saat jurnal dibuat dari Plotting Kelas pada tahun
 * pelajaran jurnal (BR-22), sehingga barisnya disimpan apa adanya dan tidak
 * berubah bila plotting diubah kemudian. Indeks unik (jurnal_id, siswa_id)
 * mencegah satu siswa tercatat dua kali pada sesi yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presensi_siswa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jurnal_id')->constrained('jurnal')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnUpdate()->restrictOnDelete();

            // BR-23 — status hanya H (hadir), S (sakit), I (izin), A (alpa).
            $table->enum('status', ['H', 'S', 'I', 'A'])->default('H');
            $table->string('keterangan', 255)->nullable();

            $table->timestamps();

            $table->unique(['jurnal_id', 'siswa_id'], 'presensi_siswa_jurnal_siswa_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presensi_siswa');
    }
};
