"""
model_router.py — Smart Model Router & Quota Checker for Gemini API.

Proactively checks API quota availability for each model, caches results,
and automatically falls back to alternative models when limits are exhausted.

Features:
    1. check_model_quota()  — Lightweight probe to detect quota availability
    2. find_available_model() — Iterates model priority list to find one with quota
    3. smart_invoke()       — Wrapper with automatic retry + model fallback
    4. get_quota_status_all() — Batch check all models for sidebar display
"""

from __future__ import annotations

import time
import threading
from dataclasses import dataclass, field
from enum import Enum
from typing import Any, Callable


# ══════════════════════════════════════════════════════════════════════════════
# Constants
# ══════════════════════════════════════════════════════════════════════════════

# Model priority order: newest/best first → oldest fallback
MODEL_PRIORITY = [
    "gemini-3.5-flash",
    "gemini-3.0-flash",
    "gemini-2.5-pro",
    "gemini-2.5-flash",
    "gemini-2.0-flash",
    "gemini-1.5-flash",
]

# How long to cache a quota check result (seconds)
QUOTA_CACHE_TTL = 60

# Minimum seconds between consecutive quota checks for the same model
RATE_LIMIT_CHECK_INTERVAL = 5

# Known free-tier daily request limits (approximate — may change)
FREE_TIER_RPD: dict[str, int] = {
    "gemini-3.5-flash": 500,
    "gemini-3.0-flash": 500,
    "gemini-2.5-pro": 25,
    "gemini-2.5-flash": 500,
    "gemini-2.0-flash": 1500,
    "gemini-1.5-flash": 1500,
}


# ══════════════════════════════════════════════════════════════════════════════
# Data Classes
# ══════════════════════════════════════════════════════════════════════════════

class QuotaStatus(Enum):
    """Quota availability status for a model."""
    AVAILABLE = "available"        # ✅ Quota OK
    EXHAUSTED = "exhausted"        # ❌ 429 / RESOURCE_EXHAUSTED
    ERROR = "error"                # ⚠️ Other error (network, auth, etc.)
    UNKNOWN = "unknown"            # ❓ Not yet checked


@dataclass
class QuotaCacheEntry:
    """Cached quota check result for a specific model+key combination."""
    status: QuotaStatus = QuotaStatus.UNKNOWN
    checked_at: float = 0.0
    error_message: str = ""
    retry_after: float = 0.0  # Seconds to wait before retry (from API response)
    daily_limit: int = 0      # RPD limit extracted from error or known defaults
    error_code: str = ""      # Short error classification: '429', '403', '404', 'network', ''

    @property
    def is_expired(self) -> bool:
        """Check if cache entry has expired."""
        if self.status == QuotaStatus.UNKNOWN:
            return True
        age = time.time() - self.checked_at
        if self.status == QuotaStatus.EXHAUSTED:
            # For exhausted status, use retry_after if available, otherwise TTL
            wait_time = max(self.retry_after, QUOTA_CACHE_TTL)
            return age > wait_time
        return age > QUOTA_CACHE_TTL


@dataclass
class ModelRouterState:
    """Shared state for the model router, stored in session_state."""
    quota_cache: dict[str, QuotaCacheEntry] = field(default_factory=dict)
    current_model: str = ""
    fallback_log: list[str] = field(default_factory=list)
    _lock: threading.Lock = field(default_factory=threading.Lock)

    def get_cache_key(self, model: str, api_key: str) -> str:
        """Generate a unique cache key for model+api_key combination."""
        # Use last 8 chars of API key for privacy
        key_suffix = api_key[-8:] if len(api_key) >= 8 else api_key
        return f"{model}::{key_suffix}"

    def get_cached_status(self, model: str, api_key: str) -> QuotaCacheEntry | None:
        """Get cached quota status if not expired."""
        cache_key = self.get_cache_key(model, api_key)
        entry = self.quota_cache.get(cache_key)
        if entry and not entry.is_expired:
            return entry
        return None

    def set_cached_status(
        self, model: str, api_key: str, status: QuotaStatus,
        error_message: str = "", retry_after: float = 0.0,
        daily_limit: int = 0, error_code: str = "",
    ):
        """Update quota cache for a model+key combination."""
        cache_key = self.get_cache_key(model, api_key)
        self.quota_cache[cache_key] = QuotaCacheEntry(
            status=status,
            checked_at=time.time(),
            error_message=error_message,
            retry_after=retry_after,
            daily_limit=daily_limit,
            error_code=error_code,
        )

    def add_fallback_log(self, message: str):
        """Add a fallback event to the log (keep last 20)."""
        timestamp = time.strftime("%H:%M:%S")
        self.fallback_log.append(f"[{timestamp}] {message}")
        if len(self.fallback_log) > 20:
            self.fallback_log = self.fallback_log[-20:]


# ══════════════════════════════════════════════════════════════════════════════
# Quota Check Functions
# ══════════════════════════════════════════════════════════════════════════════

def _parse_retry_after(error_str: str) -> float:
    """Extract retry delay from error message if available."""
    import re
    # Match patterns like "retryDelay': '7s'" or "retry in 7.686232721s"
    patterns = [
        r"retry\s*(?:in|Delay['\"]?\s*[:=]\s*['\"]?)\s*(\d+(?:\.\d+)?)\s*s",
        r"retryDelay['\"]?\s*[:=]\s*['\"]?(\d+(?:\.\d+)?)\s*s",
        r"Please retry in (\d+(?:\.\d+)?)\s*s",
    ]
    for pattern in patterns:
        match = re.search(pattern, error_str, re.IGNORECASE)
        if match:
            return float(match.group(1))
    return 0.0


def _parse_daily_limit(error_str: str) -> int:
    """Extract daily request limit from 429 error message."""
    import re
    match = re.search(r'limit:\s*(\d+)', error_str)
    if match:
        return int(match.group(1))
    return 0


def check_model_quota(
    api_key: str,
    model_name: str,
    router_state: ModelRouterState | None = None,
) -> QuotaStatus:
    """
    Probe whether a model has available quota by sending a minimal request.

    Uses a tiny prompt ("Hi") with max_output_tokens=1 to minimize usage.
    Caches results to avoid spamming the API.

    Parameters
    ----------
    api_key : str
        Gemini API key to test.
    model_name : str
        Model name (e.g., "gemini-3.5-flash").
    router_state : ModelRouterState | None
        Shared router state for caching. If None, no caching.

    Returns
    -------
    QuotaStatus
        The detected quota status for this model+key.
    """
    # Check cache first
    if router_state:
        cached = router_state.get_cached_status(model_name, api_key)
        if cached:
            return cached.status

    try:
        from google import genai
        from google.genai import types

        client = genai.Client(api_key=api_key)

        # Minimal request to probe quota
        response = client.models.generate_content(
            model=model_name,
            contents=["Hi"],
            config=types.GenerateContentConfig(
                max_output_tokens=1,
                temperature=0,
            ),
        )

        status = QuotaStatus.AVAILABLE
        daily_limit = FREE_TIER_RPD.get(model_name, 0)
        if router_state:
            router_state.set_cached_status(
                model_name, api_key, status,
                daily_limit=daily_limit, error_code="",
            )
        return status

    except Exception as e:
        error_str = str(e)

        if "429" in error_str or "RESOURCE_EXHAUSTED" in error_str or "Quota" in error_str:
            retry_after = _parse_retry_after(error_str)
            daily_limit = _parse_daily_limit(error_str) or FREE_TIER_RPD.get(model_name, 0)
            status = QuotaStatus.EXHAUSTED
            if router_state:
                router_state.set_cached_status(
                    model_name, api_key, status,
                    error_message=error_str,
                    retry_after=retry_after,
                    daily_limit=daily_limit,
                    error_code="429",
                )
            return status

        elif "403" in error_str or "PERMISSION_DENIED" in error_str:
            status = QuotaStatus.ERROR
            if router_state:
                router_state.set_cached_status(
                    model_name, api_key, status,
                    error_message="Akses ditolak",
                    error_code="403",
                )
            return status

        elif "404" in error_str or "NOT_FOUND" in error_str:
            status = QuotaStatus.ERROR
            if router_state:
                router_state.set_cached_status(
                    model_name, api_key, status,
                    error_message="Model tidak tersedia",
                    error_code="404",
                )
            return status

        else:
            status = QuotaStatus.ERROR
            # Extract short reason
            short_msg = error_str[:80] if len(error_str) > 80 else error_str
            if router_state:
                router_state.set_cached_status(
                    model_name, api_key, status,
                    error_message=short_msg,
                    error_code="other",
                )
            return status


def find_available_model(
    api_keys: list[str],
    model_priority: list[str] | None = None,
    router_state: ModelRouterState | None = None,
    on_status: Callable[[str], None] | None = None,
) -> tuple[str, str] | None:
    """
    Find the first model with available quota across all API keys.

    Iterates through models in priority order, checking each API key.

    Parameters
    ----------
    api_keys : list[str]
        List of Gemini API keys.
    model_priority : list[str] | None
        Model names in priority order. Uses MODEL_PRIORITY if None.
    router_state : ModelRouterState | None
        Shared state for caching.
    on_status : callable | None
        Callback for status updates.

    Returns
    -------
    tuple[str, str] | None
        (model_name, api_key) of the first available combination, or None.
    """
    if model_priority is None:
        model_priority = MODEL_PRIORITY

    for model in model_priority:
        for api_key in api_keys:
            if on_status:
                on_status(f"Mengecek quota {model}...")

            status = check_model_quota(api_key, model, router_state)

            if status == QuotaStatus.AVAILABLE:
                if router_state:
                    router_state.current_model = model
                    router_state.add_fallback_log(f"✅ Model {model} tersedia")
                return (model, api_key)

            elif status == QuotaStatus.EXHAUSTED:
                if on_status:
                    on_status(f"⚠️ {model} — quota habis, mencoba model berikutnya...")
                if router_state:
                    router_state.add_fallback_log(f"❌ {model} — quota habis")

            elif status == QuotaStatus.ERROR:
                if router_state:
                    cached = router_state.get_cached_status(model, api_key)
                    err_msg = cached.error_message if cached else "Unknown error"
                    router_state.add_fallback_log(f"⚠️ {model} — error: {err_msg[:60]}")

    return None


def get_quota_status_all(
    api_keys: list[str],
    model_priority: list[str] | None = None,
    router_state: ModelRouterState | None = None,
) -> dict[str, QuotaStatus]:
    """
    Check quota status for all models (for sidebar display).

    Returns a dict of {model_name: QuotaStatus}.
    Uses cached results when available.
    """
    if model_priority is None:
        model_priority = MODEL_PRIORITY

    results = {}
    for model in model_priority:
        # Check if any key has quota for this model
        model_status = QuotaStatus.EXHAUSTED
        for api_key in api_keys:
            status = check_model_quota(api_key, model, router_state)
            if status == QuotaStatus.AVAILABLE:
                model_status = QuotaStatus.AVAILABLE
                break
            elif status == QuotaStatus.ERROR:
                model_status = QuotaStatus.ERROR

        results[model] = model_status

    return results


def get_quota_detail_all(
    api_keys: list[str],
    model_priority: list[str] | None = None,
    router_state: ModelRouterState | None = None,
) -> dict[str, dict]:
    """
    Check quota and return rich detail for each model for sidebar display.

    Returns dict[model_name, {status, label, error_code, error_message,
    retry_after, daily_limit, bar_pct, bar_color}].
    """
    if model_priority is None:
        model_priority = MODEL_PRIORITY

    # First, run normal status checks
    get_quota_status_all(api_keys, model_priority, router_state)

    details: dict[str, dict] = {}
    for model in model_priority:
        # Aggregate across keys: pick best status
        best_status = QuotaStatus.UNKNOWN
        best_entry: QuotaCacheEntry | None = None

        for api_key in api_keys:
            if router_state:
                entry = router_state.get_cached_status(model, api_key)
                if entry:
                    if entry.status == QuotaStatus.AVAILABLE:
                        best_status = QuotaStatus.AVAILABLE
                        best_entry = entry
                        break
                    elif best_entry is None:
                        # Keep the first non-UNKNOWN entry as fallback
                        best_status = entry.status
                        best_entry = entry

        if best_entry is None:
            best_entry = QuotaCacheEntry()

        daily_limit = best_entry.daily_limit or FREE_TIER_RPD.get(model, 0)

        # Determine bar percentage and color
        if best_status == QuotaStatus.AVAILABLE:
            bar_pct = 100
            bar_color = "#4ade80"  # green
            label = "Tersedia"
            sub_label = f"{daily_limit} RPD" if daily_limit else "Ready"
        elif best_status == QuotaStatus.EXHAUSTED:
            bar_pct = 0
            bar_color = "#f87171"  # red
            label = "Quota Habis"
            retry = best_entry.retry_after
            sub_label = f"Retry {retry:.0f}s" if retry > 0 else "Limit tercapai"
        elif best_status == QuotaStatus.ERROR:
            bar_pct = 0
            bar_color = "#fbbf24"  # yellow
            code = best_entry.error_code
            if code == "404":
                label = "Tidak Tersedia"
                sub_label = "Model tidak ditemukan"
            elif code == "403":
                label = "Akses Ditolak"
                sub_label = "API key invalid"
            else:
                label = "Error"
                sub_label = best_entry.error_message[:40] if best_entry.error_message else "Gagal mengecek"
        else:
            bar_pct = 0
            bar_color = "rgba(255,255,255,0.15)"
            label = "Belum Dicek"
            sub_label = ""

        details[model] = {
            "status": best_status,
            "label": label,
            "sub_label": sub_label,
            "error_code": best_entry.error_code,
            "daily_limit": daily_limit,
            "retry_after": best_entry.retry_after,
            "bar_pct": bar_pct,
            "bar_color": bar_color,
        }

    return details


# ══════════════════════════════════════════════════════════════════════════════
# Smart Invoke — Automatic Retry with Model Fallback
# ══════════════════════════════════════════════════════════════════════════════

def smart_invoke(
    invoke_fn: Callable[[str, str], Any],
    api_keys: list[str],
    preferred_model: str | None = None,
    model_priority: list[str] | None = None,
    router_state: ModelRouterState | None = None,
    on_status: Callable[[str], None] | None = None,
    on_model_switch: Callable[[str, str], None] | None = None,
    max_retries_per_model: int = 2,
) -> tuple[Any, str, str]:
    """
    Execute an API call with automatic model fallback on quota exhaustion.

    Parameters
    ----------
    invoke_fn : Callable[[str, str], Any]
        Function to call: invoke_fn(api_key, model_name) -> result.
        Should raise on failure.
    api_keys : list[str]
        List of API keys.
    preferred_model : str | None
        Preferred model to try first.
    model_priority : list[str] | None
        Fallback model list. Uses MODEL_PRIORITY if None.
    router_state : ModelRouterState | None
        Shared state for caching.
    on_status : callable | None
        Status update callback.
    on_model_switch : callable | None
        Called when model switches: on_model_switch(old_model, new_model).
    max_retries_per_model : int
        Max retries per model before moving to next.

    Returns
    -------
    tuple[Any, str, str]
        (result, used_model, used_api_key)

    Raises
    ------
    RuntimeError
        If all models and keys are exhausted.
    """
    if model_priority is None:
        model_priority = list(MODEL_PRIORITY)

    # Build ordered model list: preferred first, then rest
    models_to_try = []
    if preferred_model and preferred_model in model_priority:
        models_to_try.append(preferred_model)
        for m in model_priority:
            if m != preferred_model:
                models_to_try.append(m)
    else:
        models_to_try = list(model_priority)

    last_error = None
    previous_model = None

    for model in models_to_try:
        for api_key in api_keys:
            # Check cache first — skip known exhausted models
            if router_state:
                cached = router_state.get_cached_status(model, api_key)
                if cached and cached.status == QuotaStatus.EXHAUSTED:
                    continue

            for attempt in range(max_retries_per_model):
                try:
                    if on_status:
                        on_status(f"Menggunakan model {model}...")

                    result = invoke_fn(api_key, model)

                    # Success! Update cache and return
                    if router_state:
                        router_state.set_cached_status(model, api_key, QuotaStatus.AVAILABLE)
                        router_state.current_model = model

                    return (result, model, api_key)

                except Exception as e:
                    error_str = str(e)
                    last_error = e

                    if "429" in error_str or "RESOURCE_EXHAUSTED" in error_str or "Quota" in error_str:
                        retry_after = _parse_retry_after(error_str)

                        if router_state:
                            router_state.set_cached_status(
                                model, api_key, QuotaStatus.EXHAUSTED,
                                error_message=error_str,
                                retry_after=retry_after,
                            )

                        if on_status:
                            on_status(f"⚠️ Quota {model} habis, mencari model lain...")

                        # Notify model switch
                        if on_model_switch and previous_model and previous_model != model:
                            on_model_switch(previous_model, model)

                        if router_state:
                            router_state.add_fallback_log(
                                f"❌ {model} quota habis (key ...{api_key[-4:]})"
                            )

                        # Break inner retry loop — move to next key/model
                        break

                    elif "503" in error_str or "UNAVAILABLE" in error_str:
                        # Server busy — wait and retry same model
                        if on_status:
                            on_status(f"Server sibuk, retry ({attempt+1}/{max_retries_per_model})...")
                        time.sleep(2 * (attempt + 1))
                        continue

                    else:
                        # Non-quota error — raise immediately
                        raise

            previous_model = model

    # All models exhausted
    raise RuntimeError(
        f"Semua model dan API key telah kehabisan quota. "
        f"Silakan tunggu beberapa saat atau tambah API key baru.\n"
        f"Error terakhir: {last_error}"
    )


# ══════════════════════════════════════════════════════════════════════════════
# Utility Functions
# ══════════════════════════════════════════════════════════════════════════════

def get_status_emoji(status: QuotaStatus) -> str:
    """Get HTML dot indicator for quota status display."""
    return {
        QuotaStatus.AVAILABLE: '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#4ade80;"></span>',
        QuotaStatus.EXHAUSTED: '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#f87171;"></span>',
        QuotaStatus.ERROR: '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#fbbf24;"></span>',
        QuotaStatus.UNKNOWN: '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.2);"></span>',
    }.get(status, '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.2);"></span>')


def get_status_label(status: QuotaStatus) -> str:
    """Get human-readable label for quota status."""
    return {
        QuotaStatus.AVAILABLE: "Tersedia",
        QuotaStatus.EXHAUSTED: "Quota Habis",
        QuotaStatus.ERROR: "Error",
        QuotaStatus.UNKNOWN: "Belum Dicek",
    }.get(status, "Unknown")


def format_model_display(model: str, status: QuotaStatus) -> str:
    """Format model name with status for display."""
    dot = get_status_emoji(status)
    label = get_status_label(status)
    return f"{dot} {model} — {label}"
