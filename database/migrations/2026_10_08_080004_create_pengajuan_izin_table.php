<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.4 — Pengajuan izin / sakit / dinas / cuti (FR-IZN-01..09).
 *
 * BR-25 memakai tabel ini untuk menentukan hari yang tidak mewajibkan presensi:
 * izin, sakit, dan cuti yang disetujui membebaskan presensi; dinas tidak.
 * Karena itu `jenis` harus dibaca bersama `status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_izin', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->restrictOnDelete();

            $table->enum('jenis', ['izin', 'sakit', 'dinas', 'cuti']);
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->text('alasan');
            $table->string('lampiran_path')->nullable()->comment('surat dokter / surat tugas, opsional');

            // FR-IZN-02 — hanya bermakna untuk jenis dinas.
            $table->boolean('presensi_luar_radius')->default(false);

            $table->enum('status', ['menunggu', 'disetujui', 'ditolak', 'dibatalkan'])->default('menunggu');
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->text('catatan_penyetuju')->nullable();

            // FR-IZN-08 — pengajuan atas nama pegawai oleh admin langsung berstatus disetujui.
            $table->boolean('dibuat_oleh_admin')->default(false);

            // BR-30 — penanda retensi berkas: lampiran boleh dihapus tanpa menghapus catatannya.
            $table->timestamp('lampiran_dihapus_pada')->nullable();

            $table->timestamps();

            $table->index(['pegawai_id', 'tanggal_mulai', 'tanggal_selesai'], 'pengajuan_izin_pegawai_rentang_idx');
            $table->index(['status', 'tanggal_mulai'], 'pengajuan_izin_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_izin');
    }
};
