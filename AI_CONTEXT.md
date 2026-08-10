# AI Context: Pricelist Scanner Automation Dashboard

## Overview
This is a Laravel + Vue.js (Inertia) application designed to automate the extraction of internet package pricing from promotional images (brochures) using AI (Google Gemini).

## Tech Stack
- **Backend:** Laravel 11.x (PHP 8.2+)
- **Frontend:** Vue.js 3 (Composition API), Inertia.js, Tailwind CSS
- **AI / Pipeline:** Python (FastAPI) and Gemini API. Located in `src/`.
- **Database:** SQLite / MySQL. 

## Architecture & Data Flow
1. **User Uploads Image/ZIP:** Vue -> Laravel `ScannerController@store`.
2. **Background Processing:** Laravel dispatches `ProcessPricelistJob`.
3. **Data Extraction:** `ProcessPricelistJob` sends the images to a FastAPI Python backend (`src/fastapi_app.py` -> `pipeline.py`).
4. **AI Parsing:** `pipeline.py` uses Gemini 2.0 Flash to extract internet package details based on `EXTRACTION_PROMPT`.
5. **Data Storage:** Python returns JSON, Laravel stores it in `extracted_packages` table.
6. **Self-Learning Teacher Model:** When the user manually syncs or updates ground truth data via CSV, Laravel groups any mismatches and dispatches `TrainWorkerModelJob`. This job uses Gemini to generate a new extraction rule, which is saved to `learned_patterns`.
7. **Dynamic Prompting:** The `pipeline.py` fetches the latest `learned_patterns` via `GET /api/learned-patterns` and dynamically injects them into the Gemini prompt for subsequent scans.

## Key Terminology
- **Yield / Yield Value:** `Price / GB` (or `Price / (GB * Days)` for Unlimited packages). A lower yield means the package is cheaper per GB.
- **Category:** "Harian (Sachet)" (<=7 days), "Mingguan" (8-15 days), "Bulanan (Standar)", "Bulanan (Premium/Jumbo)" (>100k).
- **Product Type:** "Perdana" (Babycare < 90 days) or "Isi Ulang" (Non-Babycare).
- **Status:** 'pending', 'processing', 'processed', 'failed', 'cancelled'.

## Known UI Considerations
- **Dark Mode / Light Mode:** Handled via Tailwind's `dark:` classes and `isDark` state in Vue.
- **Charts:** Uses `vue-chartjs` (Chart.js) for visualization.
- **Animations:** Uses `anime.js` (User prefers v3.2.2 for stability over v4) for complex scroll and layout animations.

## Current State & Remaining Revisions
1. **Anime.js Integration:** The UI needs smooth scroll and loading bar animations integrated using Anime.js, responsive to both dark and light mode themes.
2. **Market Trend Chart:** The logic in `trendData` aggregates strictly by `DATE(created_at)`. If multiple zip files are uploaded on the same day, they show up as a single point. It needs to group by `scan_id` (or `pricelist.id`) to separate different datasets on the same day.
3. **Tooltip Count Fix:** The tooltip in the "Provider Ranking by Average Yield" chart reportedly has a missing value for the package count ("jumlah:").

*(End of Context - Keep this updated when major architectural changes occur)*
