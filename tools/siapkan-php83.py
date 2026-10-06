"""Siapkan php.ini untuk PHP 8.3 CLI (C:\\php83) — dipakai khusus proyek SIPANDU.

Tidak mengubah PHP bawaan XAMPP (C:\\xampp\\php) agar proyek lain tetap jalan.
Jalankan sekali: python siapkan-php83.py
"""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(r"C:\php83")
SRC = ROOT / "php.ini-development"
DST = ROOT / "php.ini"

# Ekstensi yang dibutuhkan Laravel 12, DomPDF, PhpSpreadsheet, dan Intervention Image.
EKSTENSI = [
    "curl",
    "fileinfo",
    "gd",
    "intl",
    "mbstring",
    "openssl",
    "pdo_mysql",
    "pdo_sqlite",
    "sqlite3",
    "zip",
    "exif",
    "sodium",
    "sockets",
    "xsl",
]

PENGATURAN = {
    "memory_limit": "512M",
    "max_execution_time": "300",
    "upload_max_filesize": "16M",
    "post_max_size": "20M",
    "date.timezone": "Asia/Jakarta",
    "display_errors": "On",
    "error_reporting": "E_ALL",
    "variables_order": "GPCS",
}


def main() -> None:
    teks = SRC.read_text(encoding="utf-8")

    # extension_dir
    teks = re.sub(r'^;?\s*extension_dir\s*=.*$', 'extension_dir = "ext"', teks, flags=re.M)

    for ext in EKSTENSI:
        pola = re.compile(rf'^;\s*extension\s*=\s*{ext}\s*$', re.M)
        if pola.search(teks):
            teks = pola.sub(f"extension={ext}", teks, count=1)
        else:
            teks += f"\nextension={ext}\n"

    for kunci, nilai in PENGATURAN.items():
        pola = re.compile(rf'^;?\s*{re.escape(kunci)}\s*=.*$', re.M)
        baris = f"{kunci} = {nilai}"
        if pola.search(teks):
            teks = pola.sub(baris, teks, count=1)
        else:
            teks += f"\n{baris}\n"

    DST.write_text(teks, encoding="utf-8")
    print(f"php.ini dibuat: {DST}")


if __name__ == "__main__":
    main()
