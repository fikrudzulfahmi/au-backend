<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kecualian WFA dihapus dari produk: pegawai yang dijadwalkan WFA tetap
 * melakukan presensi biasa, sehingga tabel pengecualian tidak lagi punya arti.
 *
 * Tabelnya dulu dibuat oleh `2026_11_09_120000_create_kecuali_wfa_table` yang
 * sudah ikut dihapus. Migrasi ini hanya membersihkan basis data yang sudah
 * pernah menjalankan migrasi lama tersebut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('kecuali_wfa');
    }

    public function down(): void
    {
        if (Schema::hasTable('kecuali_wfa')) {
            return;
        }

        Schema::create('kecuali_wfa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('tanggal');
            $table->enum('jenis', ['masuk_kantor', 'dinas', 'izin', 'cuti']);
            $table->string('alasan', 1000)->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pegawai_id', 'tanggal'], 'kecuali_wfa_pegawai_tanggal_uq');
            $table->index('tanggal', 'kecuali_wfa_tanggal_idx');
        });
    }
};
