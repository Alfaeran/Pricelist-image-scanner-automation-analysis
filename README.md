# Pricelist Scanner Automation Dashboard

Proyek ini adalah sistem untuk mengekstrak dan memproses informasi pricelist otomatis menggunakan AI (Gemini), terdiri dari:
1. **Frontend & Utama (Laravel & Vue.js)**: Menyediakan interface untuk upload, menampilkan data, dashboard, chat AI, dsb.
2. **Backend API (FastAPI - Python)**: Bertugas memproses gambar/file ZIP yang diunggah dan menghubungi API Gemini untuk ekstraksi data pricelist.

Buku panduan ini menjelaskan cara menjalankan aplikasi ini menggunakan **Docker Compose (Disarankan)** atau secara **Manual (Local Development)**.

---

## 1. Menjalankan dengan Docker Compose (Disarankan)

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
   uvicorn fastapi_app:app --host 127.0.0.1 --port 8001
   ```
*(Catatan: Anda bisa menyesuaikan port ke `8081` jika ingin menyamakan dengan docker-compose, namun pastikan `FASTAPI_URL` di Laravel `.env` disesuaikan)*.

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

## Kredensial Login Default

Setelah Anda menjalankan seeder database (`php artisan migrate --seed` atau `php artisan db:seed`), Anda bisa login menggunakan akun admin default:
- **Email**: `test@example.com`
- **Password**: `password`

---

## Troubleshooting

- **Error CORS atau FastAPI tidak merespons**: Pastikan `FASTAPI_URL` pada Laravel `.env` sudah benar dan sesuai dengan port FastAPI yang berjalan (contoh `8001` atau `8081`).
- **Gagal mengirim status webhook ke Laravel**: Jika aplikasi berjalan pada custom port (seperti `8002`), pastikan baris request di `fastapi_app.py` mengarah ke port tersebut (`http://127.0.0.1:8002/api/scanner/...`).
- **Queue/Background Task macet**: Pastikan worker Laravel (`php artisan queue:work` atau container `scanner_queue_worker`) aktif karena sistem ini mengandalkan proses background untuk memproses gambar yang besar.
