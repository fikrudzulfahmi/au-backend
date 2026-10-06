<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.5 — Foto kegiatan jurnal (FR-JRN-02, A-12).
 *
 * Terpisah dari `jurnal` karena jumlahnya dibatasi 3 dan boleh dihapus karena
 * retensi tanpa menghapus jurnalnya. `foto_dihapus_pada` menandai berkas sudah
 * dibuang tetapi barisnya tetap ada sebagai jejak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurnal_foto', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jurnal_id')->constrained('jurnal')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('foto_path', 255);
            $table->unsignedTinyInteger('urutan')->default(1);
            $table->timestamp('foto_dihapus_pada')->nullable();
            $table->timestamps();

            // A-12 — maksimal 3 foto per jurnal, jadi urutan 1..3 harus unik.
            $table->unique(['jurnal_id', 'urutan'], 'jurnal_foto_jurnal_urutan_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal_foto');
    }
};
