<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.4 — Master lokasi presensi (FR-LOK-01, FR-LOK-02, FR-LOK-06).
 *
 * BR-12 "hanya satu lokasi default" ditegakkan di level database lewat kolom
 * `penanda_default` yang bernilai 1 hanya untuk lokasi default dan NULL untuk
 * sisanya. MySQL mengizinkan banyak NULL pada indeks unik, sehingga pola ini
 * memberi jaminan "maksimal satu" tanpa perlu tabel tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lokasi_presensi', function (Blueprint $table): void {
            $table->id();
            $table->string('nama');
            $table->decimal('latitude', 10, 7)->comment('derajat, -90..90');
            $table->decimal('longitude', 10, 7)->comment('derajat, -180..180');
            $table->unsignedInteger('radius_m')->comment('radius sah dalam meter (FR-LOK-06: tidak di-hardcode)');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            // BR-12 — jaring database: hanya satu baris boleh bernilai 1.
            $table->unsignedTinyInteger('penanda_default')->nullable()->unique('lokasi_presensi_default_uq');

            $table->timestamps();

            $table->index('is_active', 'lokasi_presensi_aktif_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lokasi_presensi');
    }
};
