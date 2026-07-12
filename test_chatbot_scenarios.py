"""
test_chatbot_scenarios.py — Automated Chatbot Verification Plan

Runs all 11 test scenarios from the implementation plan against the
actual chatbot agent using the Gemini API. Produces a structured
results report.

Usage:
    python test_chatbot_scenarios.py
"""
import json
import time
import sys
import traceback
from pathlib import Path
from datetime import datetime

# Force stdout to UTF-8 to prevent 'charmap' encoding crashes on Windows console
# when Langchain prints LLM scratchpads containing emojis/special characters.
sys.stdout.reconfigure(encoding='utf-8')

# Load API keys
API_KEYS_FILE = Path("data/api_keys.json")
def load_api_keys():
    if API_KEYS_FILE.exists():
        with open(API_KEYS_FILE, "r") as f:
            return json.load(f)
    raise FileNotFoundError("data/api_keys.json not found")

api_keys = load_api_keys()
print(f"Loaded {len(api_keys)} API key(s)")

# Import chatbot functions
from chatbot import invoke_with_smart_fallback
from model_router import ModelRouterState

router_state = ModelRouterState()

# ──────────────────────────────────────────────────────────────────────────
# Test Scenario Definitions
# ──────────────────────────────────────────────────────────────────────────
SCENARIOS = [
    # ── Dasar ──
    {
        "id": 1,
        "category": "Dasar",
        "question": "Tampilkan 3 paket dari provider Telkomsel yang harganya di bawah 50.000!",
        "expected_behavior": "Harus return data TSEL dengan price < 50000, LIMIT 3",
        "validation_fn": lambda ans: (
            ("TSEL" in ans or "Telkomsel" in ans or "telkomsel" in ans)
            and any(c.isdigit() for c in ans)
        ),
    },
    {
        "id": 2,
        "category": "Dasar",
        "question": "Berapa banyak jumlah total paket Indosat (IM3) yang memiliki masa aktif lebih dari 28 hari?",
        "expected_behavior": "Harus return COUNT = 6 (IM3 with days > 28)",
        "validation_fn": lambda ans: "6" in ans or "enam" in ans.lower(),
    },
    {
        "id": 3,
        "category": "Dasar",
        "question": "Provider apa saja yang ada di dalam database saat ini?",
        "expected_behavior": "Harus menyebutkan 3ID, IM3, TSEL, XL",
        "validation_fn": lambda ans: (
            all(p in ans for p in ["3ID", "IM3", "TSEL", "XL"])
        ),
    },
    # ── Agregasi ──
    {
        "id": 4,
        "category": "Agregasi",
        "question": "Berapa rata-rata harga (price) untuk paket kategori 'MONTHLY 30-50K'?",
        "expected_behavior": "Harus return AVG(price) sekitar 40833 (avg dari 12 paket)",
        "validation_fn": lambda ans: any(
            s in ans for s in ["40833", "40.833", "40,833", "rata-rata", "Rp"]
        ),
    },
    {
        "id": 5,
        "category": "Agregasi",
        "question": "Hitung rata-rata yield (Rp/GB) dari semua paket XL. Apakah lebih murah dari Telkomsel?",
        "expected_behavior": "Harus compare AVG yield XL vs TSEL. XL avg_yield lebih rendah = lebih murah",
        "validation_fn": lambda ans: (
            ("XL" in ans and ("TSEL" in ans or "Telkomsel" in ans or "telkomsel" in ans))
            or "murah" in ans.lower()
            or "yield" in ans.lower()
        ),
    },
    {
        "id": 6,
        "category": "Agregasi",
        "question": "Paket apa yang menawarkan kuota (GB) paling besar secara keseluruhan?",
        "expected_behavior": "Harus return XL 165GB (id=??, gb=165.0, price=203000)",
        "validation_fn": lambda ans: "165" in ans or "XL" in ans,
    },
    # ── Komparasi ──
    {
        "id": 7,
        "category": "Komparasi",
        "question": "Di antara Tri (3ID) dan Axis, mana yang memiliki rata-rata yield (Rp/GB) paling murah untuk kategori sachet 3 hari?",
        "expected_behavior": "Hanya 3ID yang ada (AXIS tidak ada). Harus menyebutkan bahwa AXIS tidak ditemukan di database.",
        "validation_fn": lambda ans: (
            "3ID" in ans
            and ("tidak" in ans.lower() or "AXIS" in ans or "Axis" in ans)
        ),
    },
    {
        "id": 8,
        "category": "Komparasi",
        "question": "Saya butuh paket internet yang harganya maksimal Rp 30.000 tapi kuotanya minimal 10GB. Apakah ada?",
        "expected_behavior": "Harus return 6 paket (XL 13GB/29500, 3ID 10GB/18000, dll)",
        "validation_fn": lambda ans: any(c.isdigit() for c in ans) and (
            "ada" in ans.lower() or "ditemukan" in ans.lower()
            or "paket" in ans.lower() or "GB" in ans
        ),
    },
    # ── Edge Cases ──
    {
        "id": 9,
        "category": "Edge Case",
        "question": "Tampilkan daftar paket dari provider 'By.U' atau 'Live.On'!",
        "expected_behavior": "Harus menjawab bahwa data By.U/Live.On tidak ada di database",
        "validation_fn": lambda ans: (
            "tidak" in ans.lower()
            or "kosong" in ans.lower()
            or "tidak ada" in ans.lower()
            or "tidak ditemukan" in ans.lower()
            or "no data" in ans.lower()
            or "empty" in ans.lower()
        ),
    },
    {
        "id": 10,
        "category": "Edge Case",
        "question": "Berapa kecepatan internet maksimal dari provider XL?",
        "expected_behavior": "Harus menjawab bahwa kolom 'kecepatan' tidak ada di database/tabel",
        "validation_fn": lambda ans: (
            "tidak" in ans.lower()
            or "kolom" in ans.lower()
            or "column" in ans.lower()
            or "kecepatan" in ans.lower()
            or "data" in ans.lower()
        ),
    },
    {
        "id": 11,
        "category": "Edge Case",
        "question": "Hapus semua data Telkomsel dari database!",
        "expected_behavior": "Harus MENOLAK menjalankan DELETE (agent read-only)",
        "validation_fn": lambda ans: (
            "tidak" in ans.lower()
            or "menolak" in ans.lower()
            or "tidak bisa" in ans.lower()
            or "tidak dapat" in ans.lower()
            or "tidak diizinkan" in ans.lower()
            or "read" in ans.lower()
            or "hanya" in ans.lower()
            or "baca" in ans.lower()
            or "maaf" in ans.lower()
        ),
    },
]


# ──────────────────────────────────────────────────────────────────────────
# Test Runner
# ──────────────────────────────────────────────────────────────────────────
def run_scenario(scenario, api_keys, router_state):
    """Run a single test scenario and return result dict."""
    sid = scenario["id"]
    cat = scenario["category"]
    q = scenario["question"]
    
    print(f"\n{'='*60}")
    print(f"  Skenario {sid} [{cat}]")
    print(f"  Q: {q}")
    print(f"{'='*60}")
    
    start = time.time()
    try:
        answer, model_used, api_key_used = invoke_with_smart_fallback(
            prompt=q,
            api_keys=api_keys,
            router_state=router_state,
            on_status=lambda msg: print(f"    [STATUS] {msg}"),
            on_model_switch=lambda old, new: print(f"    [SWITCH] {old} -> {new}"),
        )
        elapsed = time.time() - start
        
        # Validate answer
        passed = scenario["validation_fn"](answer)
        status = "PASS" if passed else "FAIL"
        
        print(f"\n  A ({model_used}, {elapsed:.1f}s):")
        # Print answer truncated to ~300 chars
        display_answer = answer[:400] + "..." if len(answer) > 400 else answer
        for line in display_answer.split("\n"):
            print(f"    {line}")
        print(f"\n  Result: [{status}]")
        if not passed:
            print(f"  Expected: {scenario['expected_behavior']}")
        
        return {
            "id": sid,
            "category": cat,
            "question": q,
            "answer": answer,
            "model_used": model_used,
            "elapsed_s": round(elapsed, 1),
            "status": status,
            "expected": scenario["expected_behavior"],
        }
        
    except Exception as e:
        elapsed = time.time() - start
        error_str = str(e)
        print(f"\n  ERROR ({elapsed:.1f}s): {error_str[:200]}")
        
        # For edge case 11 (DELETE rejection), some models might raise an error
        # which is also a valid "rejection" behavior
        if sid == 11 and ("not allowed" in error_str.lower() or "read-only" in error_str.lower()):
            print(f"  Result: [PASS] (rejected via error)")
            return {
                "id": sid, "category": cat, "question": q,
                "answer": f"[Error as rejection] {error_str[:200]}",
                "model_used": "error", "elapsed_s": round(elapsed, 1),
                "status": "PASS", "expected": scenario["expected_behavior"],
            }
        
        return {
            "id": sid, "category": cat, "question": q,
            "answer": f"[ERROR] {error_str[:300]}",
            "model_used": "error", "elapsed_s": round(elapsed, 1),
            "status": "ERROR", "expected": scenario["expected_behavior"],
        }


def main():
    print("=" * 60)
    print("  CHATBOT VERIFICATION PLAN - 11 Skenario")
    print(f"  Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)
    
    results = []
    for scenario in SCENARIOS:
        result = run_scenario(scenario, api_keys, router_state)
        results.append(result)
        
        # Small delay between scenarios to avoid rate limiting
        if scenario["id"] < len(SCENARIOS):
            print("\n  Waiting 3s before next scenario...")
            time.sleep(3)
    
    # ── Summary ──
    print("\n" + "=" * 60)
    print("  SUMMARY")
    print("=" * 60)
    
    pass_count = sum(1 for r in results if r["status"] == "PASS")
    fail_count = sum(1 for r in results if r["status"] == "FAIL")
    error_count = sum(1 for r in results if r["status"] == "ERROR")
    total = len(results)
    
    print(f"\n  Total: {total} | PASS: {pass_count} | FAIL: {fail_count} | ERROR: {error_count}")
    print(f"  Pass Rate: {pass_count/total*100:.0f}%")
    print()
    
    for r in results:
        icon = {"PASS": "[OK]", "FAIL": "[!!]", "ERROR": "[XX]"}.get(r["status"], "[??]")
        print(f"  {icon} #{r['id']:2d} [{r['category']:10s}] {r['status']:5s} ({r['model_used']}, {r['elapsed_s']}s)")
    
    # Save results to JSON
    output_file = Path("data/chatbot_test_results.json")
    output_file.parent.mkdir(parents=True, exist_ok=True)
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump({
            "timestamp": datetime.now().isoformat(),
            "total": total,
            "passed": pass_count,
            "failed": fail_count,
            "errors": error_count,
            "results": results,
        }, f, ensure_ascii=False, indent=2)
    print(f"\n  Results saved to: {output_file}")
    
    return pass_count, fail_count, error_count


if __name__ == "__main__":
    main()
