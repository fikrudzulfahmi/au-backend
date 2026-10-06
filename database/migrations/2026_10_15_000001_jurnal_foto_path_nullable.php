<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-30 — setelah berkas lampiran jurnal dibuang karena retensi, kolom `foto_path`
 * diisi NULL (bukan dibiarkan menunjuk berkas yang sudah tiada). Kolom asalnya
 * `string(255)` NOT NULL sehingga perlu dibuat nullable agar aturan itu dapat
 * ditegakkan. Tidak ada perubahan lain pada tabel `jurnal_foto`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurnal_foto', function (Blueprint $table): void {
            $table->string('foto_path', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('jurnal_foto', function (Blueprint $table): void {
            $table->string('foto_path', 255)->nullable(false)->change();
        });
    }
};
