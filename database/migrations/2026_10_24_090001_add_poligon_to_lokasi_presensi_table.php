<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom zona poligon pada lokasi presensi.
 *
 * Batas fisik sekolah di lapangan sering kali tidak melingkar (persegi atau bersegi banyak).
 * Kolom `poligon` menyimpan daftar koordinat [lat, lng] yang membentuk poligon tertutup
 * untuk validasi presensi yang lebih presisi daripada radius lingkaran tunggal.
 * Kolom bersifat nullable agar tidak memerlukan backfill dan aman untuk produksi;
 * lokasi tanpa poligon tetap menggunakan radius lingkaran (Haversine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lokasi_presensi', function (Blueprint $table): void {
            $table->json('poligon')->nullable()->after('radius_m');
        });
    }

    public function down(): void
    {
        Schema::table('lokasi_presensi', function (Blueprint $table): void {
            $table->dropColumn('poligon');
        });
    }
};
