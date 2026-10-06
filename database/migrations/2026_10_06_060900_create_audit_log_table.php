<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.1 / FR-SEC-05 — Audit log. Tidak dapat diubah dari UI (9).
 * Diisi pada: login gagal, reset perangkat, koreksi presensi,
 * persetujuan/penolakan, naik kelas, perubahan jurnal, perubahan pengaturan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aksi');
            $table->string('objek_tipe')->nullable();
            $table->unsignedBigInteger('objek_id')->nullable();
            $table->json('data_lama')->nullable();
            $table->json('data_baru')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('waktu');

            $table->index(['objek_tipe', 'objek_id']);
            $table->index('waktu');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
