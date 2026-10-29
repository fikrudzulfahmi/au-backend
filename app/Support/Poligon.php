<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Utilitas pemeriksaan dan kalkulasi spasial poligon zona presensi.
 *
 * Digunakan untuk mendukung batas presensi berbentuk poligon (misalnya area sekolah
 * yang tidak melingkar) sebagai alternatif atau pelengkap radius Haversine.
 */
final class Poligon
{
    /** Toleransi jarak koordinat (derajat) untuk mendeteksi titik berada di garis tepi. */
    private const TOLERANSI_TEPI = 1e-9;

    /**
     * Memeriksa apakah data poligon memiliki format yang sah:
     * - Berupa array dengan minimal 3 titik koordinat.
     * - Setiap titik berupa pasangan koordinat [lat, lng] bertipe numerik.
     * - Latitude dalam rentang -90..90 dan longitude dalam rentang -180..180.
     */
    public static function sah(mixed $poligon): bool
    {
        if (! is_array($poligon) || count($poligon) < 3) {
            return false;
        }

        foreach ($poligon as $titik) {
            if (! is_array($titik)) {
                return false;
            }

            if (array_key_exists(0, $titik) && array_key_exists(1, $titik) && count($titik) === 2) {
                $lat = $titik[0];
                $lng = $titik[1];
            } elseif (array_key_exists('lat', $titik) && array_key_exists('lng', $titik) && count($titik) === 2) {
                $lat = $titik['lat'];
                $lng = $titik['lng'];
            } elseif (array_key_exists('latitude', $titik) && array_key_exists('longitude', $titik) && count($titik) === 2) {
                $lat = $titik['latitude'];
                $lng = $titik['longitude'];
            } else {
                return false;
            }

            if (! is_numeric($lat) || ! is_numeric($lng)) {
                return false;
            }

            $latFloat = (float) $lat;
            $lngFloat = (float) $lng;

            if (is_nan($latFloat) || is_nan($lngFloat) || is_infinite($latFloat) || is_infinite($lngFloat)) {
                return false;
            }

            if ($latFloat < -90.0 || $latFloat > 90.0 || $lngFloat < -180.0 || $lngFloat > 180.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mengubah data poligon mentah menjadi daftar koordinat float terstandar.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    public static function normalisasi(mixed $poligon): ?array
    {
        if (! self::sah($poligon)) {
            return null;
        }

        $hasil = [];

        /** @var array $poligon */
        foreach ($poligon as $titik) {
            if (array_key_exists(0, $titik) && array_key_exists(1, $titik)) {
                $lat = (float) $titik[0];
                $lng = (float) $titik[1];
            } elseif (array_key_exists('lat', $titik) && array_key_exists('lng', $titik)) {
                $lat = (float) $titik['lat'];
                $lng = (float) $titik['lng'];
            } else {
                $lat = (float) $titik['latitude'];
                $lng = (float) $titik['longitude'];
            }

            $hasil[] = [$lat, $lng];
        }

        return $hasil;
    }

    /**
     * Memeriksa apakah koordinat (lat, lng) berada di dalam poligon.
     * Menggunakan algoritma ray casting (aturan even-odd) dengan penanganan titik di tepi.
     *
     * `$toleransiMeter` memberi kelonggaran di luar tepi poligon untuk menyerap galat
     * GPS perangkat: tanpa itu, jitter 10–30 m di dekat pagar membuat presensi yang sah
     * dinilai "luar zona". Toleransi harus berasal dari akurasi GPS yang dilaporkan.
     *
     * @param  array<mixed>  $poligon
     */
    public static function memuat(array $poligon, float $lat, float $lng, float $toleransiMeter = 0.0): bool
    {
        $titik = self::normalisasi($poligon);

        if ($titik === null) {
            return false;
        }

        $jumlah = count($titik);
        $diDalam = false;

        for ($i = 0, $j = $jumlah - 1; $i < $jumlah; $j = $i++) {
            $latI = $titik[$i][0];
            $lngI = $titik[$i][1];
            $latJ = $titik[$j][0];
            $lngJ = $titik[$j][1];

            // Titik yang berada tepat di tepi atau sudut poligon dianggap sah berada di dalam.
            if (self::titikPadaRuasGaris($lat, $lng, $latI, $lngI, $latJ, $lngJ, self::TOLERANSI_TEPI)) {
                return true;
            }

            // Ray casting horizontal ke arah bujur positif (timur).
            if (($latI > $lat) !== ($latJ > $lat)) {
                $lngPerpotongan = $lngI + ($lat - $latI) * ($lngJ - $lngI) / ($latJ - $latI);
                if ($lng < $lngPerpotongan) {
                    $diDalam = ! $diDalam;
                }
            }
        }

        if ($diDalam) {
            return true;
        }

        // Zona tepi: titik di luar garis tetapi masih dalam radius galat GPS dianggap sah.
        return $toleransiMeter > 0.0 && self::jarakKeTepi($titik, $lat, $lng) <= $toleransiMeter;
    }

    /**
     * Titik pusat poligon (rata-rata verteks) dipakai sebagai `latitude`/`longitude`
     * lokasi: peta, radius cadangan klien lama, dan pengurutan "lokasi terdekat"
     * semuanya memakai titik ini, sehingga titik pusat harus berada di dalam areal.
     *
     * @param  array<mixed>  $poligon
     * @return array{lat: float, lng: float}|null
     */
    public static function pusat(array $poligon): ?array
    {
        $titik = self::normalisasi($poligon);

        if ($titik === null) {
            return null;
        }

        $jumlah = count($titik);
        $lat = 0.0;
        $lng = 0.0;

        foreach ($titik as [$tLat, $tLng]) {
            $lat += $tLat;
            $lng += $tLng;
        }

        return ['lat' => $lat / $jumlah, 'lng' => $lng / $jumlah];
    }

    /**
     * Jarak terdekat (meter) dari titik ke tepi poligon.
     *
     * Proyeksi datar (equirectangular) dipakai karena rentang poligon sekolah hanya
     * puluhan–ratusan meter, sehingga galatnya jauh di bawah 1 cm.
     *
     * @param  non-empty-list<array{0: float, 1: float}>  $titik
     */
    public static function jarakKeTepi(array $titik, float $lat, float $lng): float
    {
        $meterPerDerajatLat = 110540.0;
        $meterPerDerajatLng = 111320.0 * cos(deg2rad($lat));

        $terdekat = INF;
        $jumlah = count($titik);

        for ($i = 0, $j = $jumlah - 1; $i < $jumlah; $j = $i++) {
            $ax = ($titik[$j][1] - $lng) * $meterPerDerajatLng;
            $ay = ($titik[$j][0] - $lat) * $meterPerDerajatLat;
            $bx = ($titik[$i][1] - $lng) * $meterPerDerajatLng;
            $by = ($titik[$i][0] - $lat) * $meterPerDerajatLat;

            // Jarak titik (0,0) ke segmen A–B pada bidang meter.
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $panjangSq = ($dx * $dx) + ($dy * $dy);

            $t = $panjangSq <= 0.0 ? 0.0 : max(0.0, min(1.0, -(($ax * $dx) + ($ay * $dy)) / $panjangSq));

            $jarak = sqrt((($ax + ($t * $dx)) ** 2) + (($ay + ($t * $dy)) ** 2));

            if ($jarak < $terdekat) {
                $terdekat = $jarak;
            }
        }

        return $terdekat;
    }

    /**
     * Menghitung apakah titik berada pada ruas garis dengan toleransi jarak kuadrat.
     */
    private static function titikPadaRuasGaris(
        float $pLat,
        float $pLng,
        float $aLat,
        float $aLng,
        float $bLat,
        float $bLng,
        float $toleransi = self::TOLERANSI_TEPI
    ): bool {
        $dx = $bLng - $aLng;
        $dy = $bLat - $aLat;
        $panjangSq = ($dx * $dx) + ($dy * $dy);

        $px = $pLng - $aLng;
        $py = $pLat - $aLat;

        $toleransiSq = $toleransi * $toleransi;

        // Ujung ruas berimpit (panjang garis mendekati nol).
        if ($panjangSq <= $toleransiSq) {
            $jarakSq = ($px * $px) + ($py * $py);

            return $jarakSq <= $toleransiSq;
        }

        // Nilai t proyeksi ortogonal titik P ke garis AB: t = (AP . AB) / |AB|^2.
        $t = ($px * $dx + $py * $dy) / $panjangSq;

        if ($t < 0.0) {
            $jarakSq = ($px * $px) + ($py * $py);

            return $jarakSq <= $toleransiSq;
        }

        if ($t > 1.0) {
            $qx = $pLng - $bLng;
            $qy = $pLat - $bLat;
            $jarakSq = ($qx * $qx) + ($qy * $qy);

            return $jarakSq <= $toleransiSq;
        }

        $projLat = $aLat + ($t * $dy);
        $projLng = $aLng + ($t * $dx);
        $jarakSq = (($pLat - $projLat) ** 2) + (($pLng - $projLng) ** 2);

        return $jarakSq <= $toleransiSq;
    }
}
