# Cara Download & Install Pricelist Scanner (Windows)

Panduan untuk pengguna. Tidak perlu install PHP, Node, atau Python — semuanya sudah
ada di dalam installer.

## 1. Download

1. Buka halaman rilis:
   **https://github.com/Alfaeran/Pricelist-image-scanner-automation-analysis/releases**
2. Pilih rilis paling atas (`Latest`).
3. Di bagian **Assets**, klik file berakhiran `.exe`, contoh:
   `Pricelist Scanner Setup 1.0.0.exe`
4. File akan tersimpan di folder **Downloads**.

> Butuh Windows 10 atau 11, 64-bit. Ukuran unduhan sekitar 200–400 MB.

## 2. Install

1. Klik dua kali file `.exe` yang sudah diunduh.
2. Windows akan menampilkan **"Windows protected your PC"**. Ini normal untuk
   aplikasi yang belum dibeli sertifikat penandatanganannya.
   Klik **More info** → **Run anyway**.
3. Ikuti langkah installer sampai selesai.
4. Aplikasi terbuka otomatis, dan pintasan **Pricelist Scanner** ada di Start Menu.

Saat pertama kali dibuka, aplikasi menyiapkan database-nya sendiri. Proses ini
memakan beberapa detik — biarkan jendela terbuka.

## 3. Pengaturan awal

### Nomor yang boleh chat bot WhatsApp

1. Buka tab **WhatsApp Bot (AI)**.
2. Di panel **Nomor yang Boleh Chat Bot**, isi nomor yang diizinkan,
   pisahkan dengan koma. Format `08xx` maupun `628xx` sama-sama diterima:

   ```
   081234567890, 6285842041644
   ```

3. Klik **Simpan**.

Kosongkan kolom itu kalau ingin semua nomor bisa memakai bot. Badge di sebelah
kanan menunjukkan status aktif: *Whitelist aktif* atau *Semua nomor diizinkan*.

### Menyambungkan WhatsApp

Kalau driver yang aktif adalah **Evolution**, panel QR Code muncul di tab yang sama:

1. Buka WhatsApp di HP.
2. **Setelan** → **Perangkat Tertaut**.
3. Pindai QR Code yang tampil di aplikasi.

## 4. Update ke versi baru

Download `.exe` terbaru dari halaman Releases dan jalankan seperti biasa.
Installer menimpa versi lama. **Data dan pengaturan tidak hilang** — database
tersimpan terpisah dari aplikasi.

## 5. Kalau ada masalah

| Gejala | Yang perlu dilakukan |
|---|---|
| Jendela putih / kosong saat dibuka | Tutup, buka lagi. Kalau tetap, restart Windows. |
| SmartScreen tidak memberi opsi Run anyway | Klik kanan file `.exe` → **Properties** → centang **Unblock** → **OK**. |
| Bot tidak membalas | Cek nomor pengirim sudah ada di daftar **Nomor yang Boleh Chat Bot**. |
| Antivirus memblokir | Tambahkan folder instalasi ke pengecualian antivirus. |

Laporkan kendala lain di
**https://github.com/Alfaeran/Pricelist-image-scanner-automation-analysis/issues**
