<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.4 — Presensi pegawai (FR-PRS-01..13, BR-10, BR-11, BR-13, BR-15..18).
 *
 * Satu baris = satu pegawai pada satu tanggal, menampung presensi MASUK dan
 * PULANG sekaligus (BR-10: pulang hanya setelah masuk, masing-masing sekali).
 * Indeks unik (pegawai_id, tanggal) yang menegakkan BR-10 di level database.
 *
 * Kolom masuk_* dan pulang_* sengaja berdampingan, bukan tabel terpisah, agar
 * status hari itu dapat dibaca dengan satu query dan tidak ada baris "pulang
 * tanpa masuk" yang mungkin tersimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presensi_pegawai', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('tanggal');
            $table->foreignId('semester_id')->nullable()->constrained('semester')->cascadeOnUpdate()->nullOnDelete();

            // ---------- Presensi masuk ----------
            $table->timestamp('masuk_waktu')->nullable()->comment('waktu SERVER, bukan waktu perangkat (BR-13)');
            $table->decimal('masuk_lat', 10, 7)->nullable();
            $table->decimal('masuk_lng', 10, 7)->nullable();
            $table->unsignedInteger('masuk_akurasi_m')->nullable()->comment('akurasi GPS, meter (FR-PRS-06)');
            $table->foreignId('masuk_lokasi_id')->nullable()
                ->constrained('lokasi_presensi')->cascadeOnUpdate()->nullOnDelete()
                ->comment('lokasi terdekat/terpilih; NULL bila presensi di luar semua radius');
            $table->unsignedInteger('masuk_jarak_m')->nullable()->comment('jarak Haversine ke lokasi (BR-11)');
            $table->string('masuk_foto_path')->nullable()->comment('wajib diisi pada presensi masuk (FR-PRS-08)');
            $table->enum('masuk_status', ['hadir', 'terlambat'])->nullable();
            $table->unsignedInteger('masuk_menit_terlambat')->default(0)->comment('dibulatkan ke atas (BR-15)');
            $table->enum('masuk_validasi', ['valid', 'menunggu', 'disetujui', 'ditolak'])->nullable();
            $table->text('masuk_alasan_luar_radius')->nullable()->comment('wajib pada Jalur B (FR-PRS-07)');
            $table->foreignId('masuk_pengajuan_luar_radius_id')->nullable()
                ->constrained('pengajuan_luar_radius')->cascadeOnUpdate()->nullOnDelete()
                ->comment('terisi bila memakai Jalur A');

            // ---------- Presensi pulang ----------
            $table->timestamp('pulang_waktu')->nullable();
            $table->decimal('pulang_lat', 10, 7)->nullable();
            $table->decimal('pulang_lng', 10, 7)->nullable();
            $table->unsignedInteger('pulang_akurasi_m')->nullable();
            $table->foreignId('pulang_lokasi_id')->nullable()
                ->constrained('lokasi_presensi')->cascadeOnUpdate()->nullOnDelete();
            $table->unsignedInteger('pulang_jarak_m')->nullable();
            $table->string('pulang_foto_path')->nullable();
            $table->enum('pulang_status', ['normal', 'pulang_cepat'])->nullable();
            $table->unsignedInteger('pulang_menit_cepat')->default(0)->comment('BR-16');
            $table->enum('pulang_validasi', ['valid', 'menunggu', 'disetujui', 'ditolak'])->nullable();
            $table->text('pulang_alasan_luar_radius')->nullable();
            $table->foreignId('pulang_pengajuan_luar_radius_id')->nullable()
                ->constrained('pengajuan_luar_radius')->cascadeOnUpdate()->nullOnDelete();

            // ---------- Keputusan & koreksi ----------
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->text('catatan_penyetuju')->nullable();
            $table->boolean('dikoreksi_admin')->default(false)->comment('FR-PRS-13, wajib disertai alasan di audit_log');

            // BR-30 — retensi foto: berkas dihapus, datanya tetap.
            $table->timestamp('foto_dihapus_pada')->nullable();

            $table->timestamps();

            // BR-10 — satu baris per pegawai per tanggal.
            $table->unique(['pegawai_id', 'tanggal'], 'presensi_pegawai_pegawai_tanggal_uq');
            $table->index('tanggal', 'presensi_pegawai_tanggal_idx');
            $table->index(['tanggal', 'masuk_validasi'], 'presensi_pegawai_tanggal_validasi_idx');
            $table->index('semester_id', 'presensi_pegawai_semester_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presensi_pegawai');
    }
};
