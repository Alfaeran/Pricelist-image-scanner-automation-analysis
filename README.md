# Pricelist Scanner Automation Dashboard

Proyek ini adalah sistem untuk mengekstrak dan memproses informasi pricelist otomatis menggunakan AI (Gemini), terdiri dari:
1. **Frontend & Utama (Laravel & Vue.js)**: Menyediakan interface untuk upload, menampilkan data, dashboard, chat AI, dsb.
2. **Backend API (FastAPI - Python)**: Bertugas memproses gambar/file ZIP yang diunggah dan menghubungi API Gemini untuk ekstraksi data pricelist.

Produk utamanya sekarang adalah **aplikasi desktop Windows (.exe)** — lihat bagian di bawah ini.
Cara-cara lain (Docker Compose / Manual / Hybrid) tetap ada untuk **deployment di server**
atau untuk pengembangan.

---

## Aplikasi Desktop (Windows .exe) — Cara Utama

Aplikasi dibungkus dengan **NativePHP + Electron** sehingga pengguna akhir cukup memasang
satu installer, tanpa perlu Docker, PostgreSQL, atau menjalankan terminal apa pun.
Database memakai **SQLite** (dibuat & dimigrasi otomatis saat pertama kali dijalankan),
dan FastAPI dijalankan sendiri oleh aplikasi sebagai proses anak pada port **8091**.

### Persyaratan
- PHP dan Electron sudah ikut di dalam installer.
- **Python 3.13 masih harus terpasang di mesin pengguna.** Installer memang membawa
  `scanner-app/venv` (berisi seluruh dependensi pipeline), **tetapi venv itu belum mandiri**:
  `pyvenv.cfg`-nya menunjuk ke interpreter dasar (`home = D:\laragon\bin\python\python-3.13`)
  dan tidak memuat `DLLs/` maupun standard library. Di mesin tanpa Python di lokasi tersebut,
  mesin AI tidak akan jalan. Set `NATIVEPHP_PYTHON_PATH` untuk menunjuk interpreter lain.
- ⚠️ **Belum benar-benar zero-dependency.** Untuk mencapainya, bundel Python *embeddable*
  (python-x.y.z-embed-amd64) beserta site-packages-nya, lalu arahkan `python_venv` ke sana.
- Untuk *membangun* installer: PHP 8.4+, Composer, dan Node.js 22+.
  Di mesin ini gunakan PHP bawaan Laragon: `D:\laragon\bin\php\php-8.4.23-Win32-vs16-x64`.
  Pastikan `scanner-app/venv` ada dan berisi dependensi dari `requirements.txt`, karena
  venv itulah yang ikut dibundel:
  ```bash
  cd scanner-app
  python -m venv venv
  ./venv/Scripts/python.exe -m pip install -r ../requirements.txt
  ```

### Menjalankan saat pengembangan
```bash
cd scanner-app
composer native:dev
```

### Membangun installer .exe
```bash
cd scanner-app
npm run build            # build aset frontend dulu
php artisan native:build
```
Hasilnya berupa installer Windows di folder `dist/`.

### Konfigurasi yang relevan
| Variabel | Default | Keterangan |
|---|---|---|
| `NATIVEPHP_PYTHON_PATH` | *(kosong)* | Override interpreter Python. Dikosongkan = pakai venv bawaan aplikasi |
| `NATIVEPHP_FASTAPI_PORT` | `8091` | Port mesin AI (FastAPI) |
| `FASTAPI_URL` | `http://127.0.0.1:8091` | Alamat FastAPI yang dipanggil Laravel |
| `LARAVEL_URL` | `http://127.0.0.1:8000` | Alamat Laravel yang dipanggil balik oleh Python |

> Tidak ada layar login. Aplikasi ini dipakai satu pengguna secara lokal, sehingga
> sebuah akun lokal dibuat dan dimasuki otomatis di setiap request.

---

## 1. Menjalankan dengan Docker Compose (untuk server)

Cara paling mudah dan cepat untuk menjalankan seluruh sistem (Database, Laravel, Worker, dan FastAPI) secara bersamaan.

### Persyaratan
- Docker & Docker Compose sudah terpasang.

### Langkah-langkah
1. Copy file konfigurasi `.env` untuk Laravel:
   ```bash
   cd scanner-app
   cp .env.example .env
   ```
   *(Pastikan konfigurasi `DB_HOST`, `DB_PORT`, dsb di `.env` sudah sesuai jika Anda mengubah konfigurasi default dari `docker-compose.yml`)*.

2. Build dan jalankan seluruh services menggunakan Docker:
   ```bash
   # Kembali ke folder root (pricelist-scanner-automation-dashboard)
   cd ..
   docker-compose up -d --build
   ```

3. Setup Database Laravel (hanya perlu dijalankan sekali):
   ```bash
   # Masuk ke dalam container laravel
   docker exec -it scanner_laravel bash
   
   # Jalankan install dependency dan migrasi
   composer install
   npm install
   php artisan key:generate
   php artisan migrate --seed
   exit
   ```

4. Aplikasi siap diakses:
   - **Laravel App**: http://localhost:8085
   - **FastAPI Endpoint**: http://localhost:8081

---

## 2. Menjalankan secara Manual (Local Development)

Jika Anda ingin menjalankan aplikasi secara lokal tanpa Docker, ikuti langkah-langkah berikut:

### Persyaratan
- PHP >= 8.1 & Composer
- Node.js & npm
- Python >= 3.9 & pip
- PostgreSQL / MySQL (Database lokal)

### A. Setup FastAPI (Python)
1. Buka terminal baru dan jalankan:
   ```bash
   # Install dependency Python
   pip install -r requirements.txt
   
   # Masuk ke folder src dan jalankan server FastAPI
   cd src
   uvicorn fastapi_app:app --host 127.0.0.1 --port 8091
   ```
*(Catatan: `8091` adalah default sekarang. Menjalankan `python fastapi_app.py` langsung juga memakai port yang sama, dan bisa diubah lewat `NATIVEPHP_FASTAPI_PORT`. Port apa pun yang dipakai, pastikan `FASTAPI_URL` di Laravel `.env` ikut disesuaikan)*.

### B. Setup Laravel & Vue (PHP & Node)
1. Buka terminal baru dan jalankan setup Laravel:
   ```bash
   cd scanner-app
   cp .env.example .env
   
   # Install dependency
   composer install
   npm install
   
   # Generate key
   php artisan key:generate
   ```

2. Konfigurasi Database dan API di `scanner-app/.env`:
   - Sesuaikan `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` dengan database lokal Anda.
   - Pastikan variabel `FASTAPI_URL` mengarah ke URL FastAPI yang berjalan (misal: `http://127.0.0.1:8001`).

3. Jalankan Migrasi Database:
   ```bash
   php artisan migrate
   ```

4. Jalankan Aplikasi secara paralel:
   Anda membutuhkan 3 terminal (atau jalankan di background) di dalam folder `scanner-app`:
   
   - **Terminal 1 (Laravel Server)**: 
     ```bash
     php artisan serve --port=8002
     ```
   - **Terminal 2 (Vite Frontend)**: 
     ```bash
     npm run dev
     ```
   - **Terminal 3 (Queue Worker)**: 
     ```bash
     php artisan queue:work
     ```

5. Aplikasi siap diakses:
   - **Laravel App**: http://localhost:8002
   
---

## 3. Mode Hybrid (Disarankan untuk Pengguna Windows dengan Laragon/XAMPP)

Mode ini sangat direkomendasikan jika Anda merasakan *lag* (karena overhead volume mount Docker di Windows).
Kita menjalankan database, queue worker, dan FastAPI (Python) di dalam Docker, tetapi aplikasi Laravel (PHP) dijalankan langsung (*native*) di host Windows.

### Langkah-langkah
1. Ubah konfigurasi `docker-compose.yml` dengan menghapus/meng-comment bagian service `laravel`.
2. Pastikan `scanner-app/.env` memiliki konfigurasi ini (seperti yang sudah diset):
   ```ini
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5433
   DB_DATABASE=scanner_db
   DB_USERNAME=postgres
   DB_PASSWORD=postgres
   FASTAPI_URL=http://127.0.0.1:8081
   ```
3. Jalankan service latar belakang (DB, FastAPI, Worker) via Docker:
   ```bash
   cd pricelist-scanner-automation-dashboard
   docker-compose up -d --build --remove-orphans
   ```
4. Jalankan Laravel secara lokal (melalui terminal atau Laragon Virtual Host):
   ```bash
   cd scanner-app
   php artisan serve --port=8000
   ```
5. Akses aplikasi Anda di `http://localhost:8000` atau melalui nama virtual host Laragon (misal: `http://scanner-app.test`). Aplikasi akan berjalan sangat kencang dan tetap terhubung dengan *backend* di dalam Docker!

---

## Autentikasi

Layar login sudah **dihapus**. Aplikasi ini dipakai satu pengguna secara lokal, sehingga
middleware `AutoAuthenticateDesktop` membuat dan memasukkan akun lokal
(`local@desktop.app`) secara otomatis pada setiap request.

---

## Troubleshooting

- **Error CORS atau FastAPI tidak merespons**: Pastikan `FASTAPI_URL` pada Laravel `.env` sesuai dengan port FastAPI yang berjalan (desktop: `8091`, Docker: `8081`).
- **Gagal mengirim status webhook ke Laravel**: Set `LARAVEL_URL` ke alamat Laravel yang sebenarnya. Alamat ini **tidak lagi di-hardcode** di `fastapi_app.py`, jadi tidak perlu mengedit kode Python. Pada mode desktop, `ProcessManager` mengisinya otomatis.
- **Aplikasi desktop tidak menemukan Python**: Set `NATIVEPHP_PYTHON_PATH` ke lokasi lengkap `python.exe`.
- **Queue/Background Task macet**: Pastikan worker Laravel (`php artisan queue:work` atau container `scanner_queue_worker`) aktif karena sistem ini mengandalkan proses background untuk memproses gambar yang besar.
