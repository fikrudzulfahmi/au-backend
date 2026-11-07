<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal WFA (Work From Anywhere) per pegawai per hari.
 *
 * Dua jenis:
 *  - `penuh`    — membebaskan presensi (seperti izin), pegawai tidak wajib hadir.
 *  - `sebagian` — jam masuk digeser (`jam_masuk` wajib diisi), presensi tetap wajib,
 *                 terlambat dihitung dari jam WFA, bukan jam_kerja.
 *
 * Jam pulang selalu mengikuti `jam_kerja` standar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_wfa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedTinyInteger('hari')->comment('1 = Senin … 7 = Minggu');
            $table->enum('jenis', ['penuh', 'sebagian'])->default('sebagian');
            $table->time('jam_masuk')->nullable()->comment('Wajib bila jenis = sebagian');
            $table->string('keterangan')->nullable()->comment('Alasan/catatan admin');
            $table->timestamps();

            $table->unique(['pegawai_id', 'hari'], 'jadwal_wfa_pegawai_hari_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_wfa');
    }
};
