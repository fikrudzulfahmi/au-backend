<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tugas terjadwal (Fase 7, BR-30)
|--------------------------------------------------------------------------
| Retensi foto dihitung per tahun pelajaran (A-07): setelah admin menandai
| tahun pelajaran `selesai`, berkas foto presensi & lampirannya dibuang.
| Dijalankan harian dini hari agar tidak berebut dengan jam sibuk presensi pagi.
| Perintahnya idempoten, jadi tidak masalah bila tidak ada yang perlu dibersihkan.
*/
Schedule::command('presensi:bersihkan-foto')
    ->dailyAt('01:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
