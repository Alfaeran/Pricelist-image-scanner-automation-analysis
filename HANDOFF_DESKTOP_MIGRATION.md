# Handoff: Pricelist Scanner — Web → Desktop Migration

## 🎯 GOAL
Migrate the **Pricelist Scanner Automation Dashboard** from a web-based Laravel+Docker app to a **standalone Windows desktop app (.exe)** using **NativePHP (Electron)**. The end-user is NOT a developer — they should double-click an installer and everything works.

---

## 📦 PROJECT LOCATION
```
D:\pricelist-scanner-automation-dashboard\
├── scanner-app/          ← Laravel 13 + Vue 3 (Inertia.js) app
│   ├── app/              ← PHP backend (Controllers, Jobs, Models, Services, Middleware)
│   ├── resources/js/     ← Vue.js frontend (Pages, Components, Layouts)
│   ├── config/           ← Includes nativephp.php config
│   ├── bootstrap/        ← app.php (middleware), providers.php
│   ├── database/         ← Migrations, seeders
│   ├── routes/           ← web.php, api.php
│   ├── composer.json     ← PHP dependencies
│   └── package.json      ← JS dependencies (Vue, Chart.js, anime.js, etc.)
├── src/                  ← Python AI pipeline
│   ├── fastapi_app.py    ← FastAPI server (image extraction, chat, export)
│   └── pipeline.py       ← Gemini AI extraction logic
├── docker-compose.yml    ← Current Docker setup (PostgreSQL, FastAPI, Queue Worker)
├── AI_CONTEXT.md         ← Quick project overview for AI assistants
└── README.md             ← Setup instructions
```

## 🧠 WHAT THE APP DOES
1. User uploads photos of internet package brochures (pricelist images)
2. Python (FastAPI + Gemini AI) extracts structured data: provider, price, GB, days, yield
3. Laravel stores results in DB, Vue.js renders interactive dashboard
4. Features: market trend charts, provider comparison heatmaps, AI chat, CSV sync, yield analysis
5. Self-learning: when user corrects data, the system trains new extraction rules

## 🏗️ TECH STACK
- **Backend:** Laravel 13.x (PHP 8.3+), Inertia.js
- **Frontend:** Vue 3 (Composition API), Tailwind CSS, Chart.js, anime.js v3.2.2
- **AI Pipeline:** Python FastAPI + Google Gemini 2.0 Flash
- **Database:** Currently PostgreSQL (Docker) → migrating to **SQLite** (zero-config)
- **Desktop Wrapper:** NativePHP with Electron (Windows .exe target)

---

## ✅ WHAT'S BEEN DONE

### Files Created
1. **`scanner-app/app/Providers/NativeAppServiceProvider.php`** — boots ProcessManager, creates SQLite DB on first run
2. **`scanner-app/app/Services/ProcessManager.php`** — manages FastAPI + queue worker as child processes (start/stop/restart/health)
3. **`scanner-app/app/Http/Middleware/AutoAuthenticateDesktop.php`** — auto-creates and logs in a "Local User" on every request (no login screen needed)

### Files Modified
4. **`scanner-app/config/nativephp.php`** — added `python_path` and `fastapi_port` config keys
5. **`scanner-app/.env.example`** — switched defaults to SQLite, added `NATIVEPHP_PYTHON_PATH` and `NATIVEPHP_FASTAPI_PORT`, changed `FASTAPI_URL` to port 8091
6. **`scanner-app/bootstrap/providers.php`** — registered `NativeAppServiceProvider`
7. **`scanner-app/bootstrap/app.php`** — added `AutoAuthenticateDesktop` middleware to web stack

### Git State
- All previous changes committed and pushed to `origin/main`
- New changes above are **NOT yet committed**

---

## ❌ WHAT REMAINS (in priority order)

### Phase 1: NativePHP Package Install (BLOCKER)
- **PHP and Composer are NOT installed natively on Windows.** The user has been running PHP through Docker/WSL.
- Need to install PHP 8.3+ and Composer on Windows PATH, OR use Laravel Herd
- Then run: `composer require nativephp/desktop` and `php artisan native:install`
- The `nativephp/desktop` package will scaffold the Electron integration

### Phase 2: Database Migration (PostgreSQL → SQLite)  
- Audit ALL migration files in `scanner-app/database/migrations/` for PostgreSQL-specific syntax
- Known issue from commit history: `COALESCE` usage — may need adjustment for SQLite
- Check for `jsonb` columns, `::cast` syntax, or PostgreSQL-specific functions
- The `ProcessPricelistJob.php` has been flagged for PostgreSQL-specific queries — verify and fix
- Ensure `database/database.sqlite` is auto-created (NativeAppServiceProvider already handles this)

### Phase 3: FastAPI Port Update
- Change default port in `src/fastapi_app.py` line 457 from `8081` to `8091`
- Update hardcoded webhook URL on line 74: `http://127.0.0.1:8000` should use env var or `webhook_url` param
- Make sure `scanner-app/.env` (the actual .env, not just .env.example) has `FASTAPI_URL=http://127.0.0.1:8091`

### Phase 4: Auth Removal — UI Side
- The Vue frontend still has login/register pages and navigation links
- Check `scanner-app/routes/web.php` and `scanner-app/routes/auth.php` — remove or skip auth routes
- Check `scanner-app/resources/js/Layouts/AuthenticatedLayout.vue` — remove logout button / user dropdown
- The middleware auto-authenticates, but the UI should not show auth-related elements

### Phase 5: README & Docker Cleanup
- Update `README.md` with a "Desktop App" section at the top
- Update `composer.json` `scripts.dev` to use `php artisan native:serve`
- Keep `docker-compose.yml` but add comments that it's for server deployment only

### Phase 6: UX Polish for Desktop
- Add a FastAPI health indicator (green/red dot) to `Scanner/Index.vue` header
- Add a "Restart AI Engine" button that calls `ProcessManager::restart('fastapi')`
- Add app version display in the layout from `config('nativephp.version')`
- Need an API endpoint for health check: `GET /api/system/health` → returns FastAPI + queue status

### Phase 7: Build & Distribution
- Run `php artisan native:build` to produce a Windows `.exe` installer
- This bundles PHP runtime + the Laravel app + Electron into a standalone installer
- Test on a clean Windows machine to verify zero-dependency installation
- Note: Python must still be installed separately (Option B from the plan)

---

## 🔑 KEY DECISIONS MADE
| Decision | Choice | Rationale |
|---|---|---|
| Desktop framework | NativePHP + Electron | Best compatibility on Windows, mature ecosystem |
| Target OS | Windows only | User requirement |
| Distribution | `.exe` installer | End-users are non-developers |
| Database | SQLite (from PostgreSQL) | Zero-config, no external DB server needed |
| Authentication | Removed (auto-login) | Single-user local app, no need for login |
| WhatsApp chatbot | Keep | User wants it for future discussion |
| Python/FastAPI | Subprocess (Option B) | User must have Python installed; app auto-starts uvicorn |
| FastAPI port | 8091 (from 8081) | Avoid conflicts with common services |

---

## 📁 KEY FILES TO UNDERSTAND

### Backend (PHP/Laravel)
- `scanner-app/app/Http/Controllers/ScannerController.php` — main controller for upload, data display
- `scanner-app/app/Jobs/ProcessPricelistJob.php` — background job that sends images to FastAPI
- `scanner-app/app/Services/ProcessManager.php` — **NEW** manages FastAPI + queue subprocess
- `scanner-app/app/Http/Middleware/AutoAuthenticateDesktop.php` — **NEW** auto-login
- `scanner-app/routes/web.php` — web routes
- `scanner-app/routes/api.php` — API routes (WhatsApp webhook, learned patterns, trends)

### Frontend (Vue.js)
- `scanner-app/resources/js/Pages/Scanner/Index.vue` — **THE main page** (~5300 lines, monolithic). Contains:
  - Image upload
  - Data table with filtering
  - Market trend charts (Line chart with provider comparison)
  - Yield distribution bar charts
  - Competitive heatmap
  - AI chat popup
  - Provider color system (IOH yellow, Telkomsel red, XL blue, etc.)
- `scanner-app/resources/js/Components/WhatsAppMonitor.vue` — WhatsApp status panel
- `scanner-app/resources/js/Components/ChartViewer.vue` — Chart rendering component
- `scanner-app/resources/js/Layouts/AuthenticatedLayout.vue` — main layout with nav

### AI Pipeline (Python)
- `src/fastapi_app.py` — FastAPI endpoints: `/api/extract`, `/api/chat`, `/api/export`, `/api/insights`, `/api/keys/check`, `/api/check-sim-age`
- `src/pipeline.py` — Core Gemini extraction logic, image preprocessing, Excel generation

### Config
- `scanner-app/config/nativephp.php` — NativePHP window config, python path, FastAPI port
- `scanner-app/.env.example` — all environment variables documented
- `AI_CONTEXT.md` — concise project overview with terminology glossary

---

## ⚠️ GOTCHAS & KNOWN ISSUES
1. **anime.js version**: User specifically wants v3.2.2 (NOT v4). v4 has breaking API changes.
2. **Scanner/Index.vue is ~5300 lines** — it's a monolith. Be careful with edits; use line ranges.
3. **Provider normalization**: "3", "3ID", "TRI" all refer to Tri (Indosat sub-brand). There's a `normalizeProvider()` function.
4. **Yield calculation**: `Price / GB` for normal packages, `Price / (GB * Days)` for Unlimited. Lower = cheaper.
5. **Dark mode**: Handled via Tailwind `dark:` classes + `isDark` ref in Vue. Both modes must work.
6. **The `.env` file** (not `.env.example`) in `scanner-app/` may have PostgreSQL settings — needs to be updated to SQLite too.
7. **composer.json** still references `laravel/octane` which isn't needed for desktop — can be removed.

---

## 🚀 SUGGESTED FIRST STEPS FOR CONTINUATION
1. Install PHP 8.3+ and Composer on Windows (or use Laravel Herd)
2. Run `composer require nativephp/desktop` in `scanner-app/`
3. Run `php artisan native:install` to scaffold Electron files
4. Update `src/fastapi_app.py` port from 8081 → 8091
5. Audit migrations for SQLite compatibility
6. Clean up auth UI elements
7. Test with `php artisan native:serve`
8. Build `.exe` with `php artisan native:build`
