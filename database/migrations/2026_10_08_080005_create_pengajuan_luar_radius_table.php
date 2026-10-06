<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.4 — Pengajuan presensi di luar radius (FR-PRS-07 Jalur A, FR-IZN-06).
 *
 * Satu baris mewakili SATU tanggal — bukan rentang — supaya presensi dapat
 * mencocokkan pengajuan yang berlaku untuk tanggal presensinya dengan pencarian
 * sederhana, dan supaya rentang dari dinas (FR-IZN-02) dapat dipecah per hari kerja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_luar_radius', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('tanggal');
            $table->text('alasan');
            $table->string('lampiran_path')->nullable();

            // Terisi bila baris ini lahir otomatis dari dinas yang disetujui (FR-IZN-02).
            $table->foreignId('pengajuan_izin_id')->nullable()
                ->constrained('pengajuan_izin')->cascadeOnUpdate()->cascadeOnDelete();

            $table->enum('status', ['menunggu', 'disetujui', 'ditolak', 'dibatalkan'])->default('menunggu');
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->text('catatan_penyetuju')->nullable();

            $table->timestamps();

            // Satu tanggal hanya boleh punya satu pengajuan luar radius per pegawai;
            // inilah yang membuat BR-17 Jalur A deterministik.
            $table->unique(['pegawai_id', 'tanggal'], 'pengajuan_luar_radius_pegawai_tanggal_uq');
            $table->index('status', 'pengajuan_luar_radius_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_luar_radius');
    }
};
