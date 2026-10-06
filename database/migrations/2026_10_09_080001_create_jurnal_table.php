<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.5 — Jurnal pembelajaran (FR-JRN-01..09, BR-20, BR-21).
 *
 * Satu baris = satu SESI mengajar, bukan satu jam pelajaran: entri jadwal
 * berurutan untuk plotting mapel dan hari yang sama digabung menjadi satu sesi
 * (FR-JRN-01), karena itu jam_ke_mulai dan jam_ke_selesai disimpan berdampingan.
 *
 * Indeks unik (plotting_mapel_id, tanggal, jam_ke_mulai) menegakkan BR-21 di
 * level database: satu sesi hanya boleh punya satu jurnal.
 *
 * `pegawai_id` dan `kelas_id` disalin dari plotting_mapel agar otorisasi
 * "guru hanya sesi miliknya" (FR-JRN-06) dan rekap per kelas tidak perlu join
 * berlapis, sekaligus tetap terjaga bila plotting berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurnal', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_id')->constrained('semester')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('plotting_mapel_id')->constrained('plotting_mapel')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('tanggal');

            $table->unsignedSmallInteger('jam_ke_mulai');
            $table->unsignedSmallInteger('jam_ke_selesai');

            $table->text('materi');
            $table->text('kegiatan');
            $table->text('catatan')->nullable();

            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('diubah_oleh')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            // BR-21 — satu sesi (plotting mapel + tanggal + jam mulai) hanya satu jurnal.
            $table->unique(['plotting_mapel_id', 'tanggal', 'jam_ke_mulai'], 'jurnal_plotmapel_tanggal_jamke_uq');
            $table->index(['pegawai_id', 'tanggal'], 'jurnal_pegawai_tanggal_ix');
            $table->index(['kelas_id', 'tanggal'], 'jurnal_kelas_tanggal_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal');
    }
};
