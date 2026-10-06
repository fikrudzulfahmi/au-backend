<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pelanggaran aturan bisnis (BR-xx / FR-xx) yang harus tampil sebagai pesan jelas,
 * bukan 500. Dirender di bootstrap/app.php menjadi {message, code, errors?}.
 */
final class AturanBisnisException extends HttpException
{
    /**
     * @param  array<string, list<string>>  $galat  galat per bidang (opsional)
     */
    public function __construct(
        string $message,
        public readonly string $kode = 'ATURAN_BISNIS',
        int $status = 422,
        public readonly array $galat = [],
    ) {
        parent::__construct($status, $message);
    }

    /** Ditolak karena tidak lolos validasi (422), opsional menempel ke sebuah bidang. */
    public static function tolak(string $pesan, string $bidang = '', string $kode = 'ATURAN_BISNIS'): self
    {
        return new self($pesan, $kode, 422, $bidang !== '' ? [$bidang => [$pesan]] : []);
    }

    /** Bertentangan dengan data yang sudah ada (409). */
    public static function konflik(string $pesan, string $kode = 'KONFLIK_DATA'): self
    {
        return new self($pesan, $kode, 409);
    }

    /**
     * Beberapa galat sekaligus, mis. hasil validasi wizard yang harus dilaporkan utuh
     * sementara tidak ada satu pun perubahan yang disimpan.
     *
     * @param  list<string>  $daftar
     */
    public static function kumpulan(string $pesan, array $daftar, string $bidang = 'keputusan', string $kode = 'VALIDASI_BERKAS'): self
    {
        return new self($pesan, $kode, 422, [$bidang => $daftar]);
    }
}
