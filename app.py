"""
app.py — Streamlit Human-in-the-Loop Dashboard
for Pricelist Scanner Automation.

Premium UI/UX inspired by Claude & Gemini chat interfaces.

Flow:
    1. Sidebar: Lokasi Konter, Gemini API Keys, DB stats, Model Quota Status.
    2. Upload: multiple images and/or ZIP files.
    3. Preprocess + Extract via Gemini (with progress bar).
    4. Review & edit data in st.data_editor (HITL).
    5. Save to SQLite database.
    6. Export to formatted Excel (with confirmation dialog).
    7. Chatbot: Agentic RAG with smart model fallback.
"""

from __future__ import annotations

import json
import math
import os
from datetime import datetime
from io import BytesIO

import numpy as np
import pandas as pd
import streamlit as st

import yaml
from yaml.loader import SafeLoader
import streamlit_authenticator as stauth

from database import get_database
from pipeline import (
    CATEGORIES_ORDER,
    PROVIDER_CODES,
    clean_dataframe,
    compute_yield,
    extract_packages_gemini,
    generate_excel,
    preprocess_image,
    resolve_uploaded_files,
)
from model_router import (
    ModelRouterState,
    QuotaStatus,
    MODEL_PRIORITY,
    FREE_TIER_RPD,
    check_model_quota,
    find_available_model,
    get_quota_status_all,
    get_quota_detail_all,
    get_status_emoji,
    get_status_label,
    format_model_display,
    smart_invoke,
)

# ──────────────────────────────────────────────────────────────────────────────
# Page config
# ──────────────────────────────────────────────────────────────────────────────
st.set_page_config(
    page_title="Pricelist Scanner",
    page_icon="favicon.png",
    layout="wide",
    initial_sidebar_state="expanded",
)

# ──────────────────────────────────────────────────────────────────────────────
# Custom CSS — Premium Dark Theme inspired by Claude & Gemini
# ──────────────────────────────────────────────────────────────────────────────
st.markdown("""
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

/* ── RESET & BASE ── */
#MainMenu {visibility: hidden;}
.stDeployButton {display: none;}
header {background-color: transparent !important;}
footer {visibility: hidden;}

html, body, [class*="css"] {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
}

.stApp {
    background: #0a0a0a;
    color: #e8e8e8;
}

/* ── SIDEBAR — Glassmorphism Dark ── */
[data-testid="stSidebar"] {
    background: linear-gradient(180deg, #141416 0%, #1a1a1e 100%) !important;
    border-right: 1px solid rgba(255,255,255,0.06) !important;
    padding-top: 1.5rem !important;
}
[data-testid="stSidebar"] > div:first-child {
    padding-top: 0 !important;
}

/* Sidebar section captions */
[data-testid="stSidebar"] .stCaption {
    color: rgba(255,255,255,0.4) !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    letter-spacing: 0.08em !important;
    text-transform: uppercase !important;
}

/* ── NAVIGATION PILLS ── */
div[data-testid="stRadio"] > label {
    display: none !important;
}
div[data-testid="stRadio"] div[role="radiogroup"] {
    gap: 2px !important;
}
div[data-testid="stRadio"] div[role="radiogroup"] > label {
    padding: 10px 16px !important;
    border-radius: 12px !important;
    background-color: transparent !important;
    color: rgba(255,255,255,0.7) !important;
    cursor: pointer !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    width: 100% !important;
    font-size: 14px !important;
    font-weight: 500 !important;
}
div[data-testid="stRadio"] div[role="radiogroup"] > label > div:first-child {
    display: none !important;
}
div[data-testid="stRadio"] div[role="radiogroup"] > label:hover {
    background-color: rgba(255,255,255,0.06) !important;
    color: #fff !important;
}
div[data-testid="stRadio"] div[role="radiogroup"] > label:has(input:checked) {
    background: linear-gradient(135deg, rgba(139,92,246,0.15), rgba(79,70,229,0.1)) !important;
    color: #c4b5fd !important;
    border: 1px solid rgba(139,92,246,0.2) !important;
}
div[data-testid="stRadio"] div[role="radiogroup"] > label:has(input:checked) p {
    font-weight: 600 !important;
    color: #c4b5fd !important;
}

/* ── SIDEBAR INPUTS ── */
[data-testid="stSidebar"] [data-baseweb="select"] > div,
[data-testid="stSidebar"] [data-baseweb="textarea"] > div,
[data-testid="stSidebar"] [data-baseweb="input"] > div {
    background-color: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    border-radius: 10px !important;
    transition: border-color 0.2s ease !important;
}
[data-testid="stSidebar"] [data-baseweb="select"] > div:focus-within,
[data-testid="stSidebar"] [data-baseweb="input"] > div:focus-within {
    border-color: rgba(139,92,246,0.5) !important;
    box-shadow: 0 0 0 2px rgba(139,92,246,0.1) !important;
}

/* ── BUTTONS — Premium Gradient ── */
div[data-testid="stButton"] button {
    border-radius: 12px !important;
    padding: 8px 20px !important;
    font-weight: 500 !important;
    font-size: 14px !important;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    border: 1px solid rgba(255,255,255,0.12) !important;
    background: rgba(255,255,255,0.04) !important;
    color: #e8e8e8 !important;
    letter-spacing: 0.01em !important;
}
div[data-testid="stButton"] button:hover {
    background: rgba(255,255,255,0.08) !important;
    border-color: rgba(255,255,255,0.2) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3) !important;
}
div[data-testid="stButton"] button[kind="primary"] {
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%) !important;
    color: #fff !important;
    border: none !important;
    box-shadow: 0 2px 8px rgba(124,58,237,0.3) !important;
}
div[data-testid="stButton"] button[kind="primary"]:hover {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 50%, #6d28d9 100%) !important;
    box-shadow: 0 4px 16px rgba(124,58,237,0.4) !important;
    transform: translateY(-1px) !important;
}

/* ── EXPANDERS ── */
div[data-testid="stExpander"] {
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 12px !important;
    background: rgba(255,255,255,0.02) !important;
    backdrop-filter: blur(10px) !important;
}
div[data-testid="stExpander"] summary {
    font-weight: 500 !important;
}

/* ── METRICS ── */
[data-testid="stMetric"] {
    background: rgba(255,255,255,0.03) !important;
    border: 1px solid rgba(255,255,255,0.06) !important;
    border-radius: 12px !important;
    padding: 16px !important;
}

/* ── DIVIDERS ── */
hr {
    border-color: rgba(255,255,255,0.06) !important;
}

/* ── CHAT INTERFACE — Claude/Gemini Inspired ── */

/* Chat container overall */
[data-testid="stChatMessage"] {
    background: transparent !important;
    border: none !important;
    padding: 16px 0 !important;
    animation: fadeInUp 0.35s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* User messages — right-aligned feel with subtle background */
[data-testid="stChatMessage"]:has([data-testid="chatAvatarIcon-user"]) {
    background: rgba(139,92,246,0.06) !important;
    border-radius: 16px !important;
    margin: 8px 0 !important;
    padding: 16px 20px !important;
    border-left: 3px solid rgba(139,92,246,0.3) !important;
}

/* Assistant messages — clean with accent border */
[data-testid="stChatMessage"]:has([data-testid="chatAvatarIcon-assistant"]) {
    background: rgba(255,255,255,0.02) !important;
    border-radius: 16px !important;
    margin: 8px 0 !important;
    padding: 16px 20px !important;
    border-left: 3px solid rgba(59,130,246,0.3) !important;
}

/* Chat message text */
[data-testid="stChatMessage"] p,
[data-testid="stChatMessage"] li {
    font-size: 14.5px !important;
    line-height: 1.7 !important;
    color: #e8e8e8 !important;
}

/* Chat avatars — sleek circles */
[data-testid="chatAvatarIcon-user"],
[data-testid="chatAvatarIcon-assistant"] {
    border-radius: 10px !important;
    width: 32px !important;
    height: 32px !important;
}

/* Chat input — floating glass pill */
[data-testid="stChatInput"] {
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    border-radius: 16px !important;
    backdrop-filter: blur(20px) !important;
    transition: all 0.3s ease !important;
}
[data-testid="stChatInput"]:focus-within {
    border-color: rgba(139,92,246,0.4) !important;
    box-shadow: 0 0 0 3px rgba(139,92,246,0.08), 0 8px 24px rgba(0,0,0,0.2) !important;
}
[data-testid="stChatInput"] textarea {
    color: #e8e8e8 !important;
    font-size: 14px !important;
}

/* ── STATUS BADGES ── */
.model-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    font-family: 'Inter', sans-serif;
}
.model-badge-active {
    background: linear-gradient(135deg, rgba(34,197,94,0.12), rgba(22,163,74,0.08));
    color: #4ade80;
    border: 1px solid rgba(34,197,94,0.2);
}
.model-badge-switching {
    background: linear-gradient(135deg, rgba(250,204,21,0.12), rgba(234,179,8,0.08));
    color: #fbbf24;
    border: 1px solid rgba(250,204,21,0.2);
}
.model-badge-error {
    background: linear-gradient(135deg, rgba(239,68,68,0.12), rgba(220,38,38,0.08));
    color: #f87171;
    border: 1px solid rgba(239,68,68,0.2);
}

/* ── TYPING INDICATOR ── */
.typing-indicator {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 12px 16px;
}
.typing-indicator span {
    width: 8px;
    height: 8px;
    background: rgba(139,92,246,0.6);
    border-radius: 50%;
    animation: typingBounce 1.4s ease-in-out infinite;
}
.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typingBounce {
    0%, 60%, 100% {
        transform: translateY(0);
        opacity: 0.4;
    }
    30% {
        transform: translateY(-6px);
        opacity: 1;
    }
}


/* ── PAGE HEADER STYLING ── */
.page-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 4px;
}
.page-header h1 {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
    background: linear-gradient(135deg, #c4b5fd, #818cf8);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* ── FILE UPLOADER ── */
[data-testid="stFileUploader"] {
    border: 2px dashed rgba(139,92,246,0.2) !important;
    border-radius: 16px !important;
    background: rgba(139,92,246,0.02) !important;
    transition: all 0.3s ease !important;
}
[data-testid="stFileUploader"]:hover {
    border-color: rgba(139,92,246,0.4) !important;
    background: rgba(139,92,246,0.04) !important;
}

/* ── DATA EDITOR ── */
[data-testid="stDataFrame"],
.stDataFrame {
    border-radius: 12px !important;
    overflow: hidden !important;
}

/* ── ALERTS & INFO BOXES ── */
div[data-testid="stAlert"] {
    border-radius: 12px !important;
    border: none !important;
    backdrop-filter: blur(10px) !important;
}

/* ── PROGRESS BAR ── */
.stProgress > div > div {
    background: linear-gradient(90deg, #7c3aed, #a78bfa, #c4b5fd) !important;
    border-radius: 8px !important;
}

/* ── DOWNLOAD BUTTON ── */
[data-testid="stDownloadButton"] button {
    border-radius: 12px !important;
    background: linear-gradient(135deg, rgba(34,197,94,0.1), rgba(22,163,74,0.05)) !important;
    color: #4ade80 !important;
    border: 1px solid rgba(34,197,94,0.2) !important;
    font-weight: 500 !important;
    transition: all 0.25s ease !important;
}
[data-testid="stDownloadButton"] button:hover {
    background: linear-gradient(135deg, rgba(34,197,94,0.15), rgba(22,163,74,0.1)) !important;
    box-shadow: 0 4px 12px rgba(34,197,94,0.2) !important;
    transform: translateY(-1px) !important;
}

/* ── SCROLLBAR ── */
::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.1);
    border-radius: 3px;
}
::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.2);
}

/* ── CHAT WELCOME AREA ── */
.chat-welcome {
    text-align: center;
    padding: 60px 20px 40px;
    animation: fadeInUp 0.5s ease;
}
.chat-welcome h2 {
    font-size: 28px;
    font-weight: 600;
    background: linear-gradient(135deg, #c4b5fd, #818cf8, #6d28d9);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 8px;
}
.chat-welcome p {
    color: rgba(255,255,255,0.5);
    font-size: 14px;
    max-width: 480px;
    margin: 0 auto;
    line-height: 1.6;
}

/* ── SUGGESTION CHIPS ── */
.suggestion-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
    margin-top: 24px;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
}

/* ── SPINNER OVERRIDE ── */
.stSpinner > div {
    border-color: rgba(139,92,246,0.3) !important;
    border-top-color: #7c3aed !important;
}
</style>
""", unsafe_allow_html=True)

# ──────────────────────────────────────────────────────────────────────────────
# Authentication
# ──────────────────────────────────────────────────────────────────────────────
with open('auth_config.yaml') as file:
    config = yaml.load(file, Loader=SafeLoader)

authenticator = stauth.Authenticate(
    config['credentials'],
    config['cookie']['name'],
    config['cookie']['key'],
    config['cookie']['expiry_days']
)

# Use columns to center and shrink the login box
col1, col2, col3 = st.columns([1, 1, 1])
with col2:
    authenticator.login()
    
    if st.session_state["authentication_status"] is False:
        st.error('Username/password is incorrect')
        st.stop()
    elif st.session_state["authentication_status"] is None:
        st.warning('Please enter your username dan password')
        st.stop()

authenticator.logout(location="sidebar")

# ──────────────────────────────────────────────────────────────────────────────
# Database singleton — cached across reruns
# ──────────────────────────────────────────────────────────────────────────────
@st.cache_resource
def _get_db():
    return get_database()

db = _get_db()


# ──────────────────────────────────────────────────────────────────────────────
# Location options
# ──────────────────────────────────────────────────────────────────────────────
_REGIONS = {
    "Central Java": ["Semarang", "Sleman", "Solo", "Yogyakarta", "Purwokerto"],
    "East Java": ["Jember", "Malang", "Surabaya", "Sidoarjo"],
    "Bali Nusa Tenggara": ["Denpasar", "Gianyar", "Singaraja"],
}


# ══════════════════════════════════════════════════════════════════════════════
# Session State Defaults
# ══════════════════════════════════════════════════════════════════════════════
from pathlib import Path

API_KEYS_FILE = Path("data/api_keys.json")

def load_saved_api_keys():
    if API_KEYS_FILE.exists():
        try:
            with open(API_KEYS_FILE, "r") as f:
                return "\n".join(json.load(f))
        except:
            pass
    return ""

def save_api_keys(keys_str):
    API_KEYS_FILE.parent.mkdir(parents=True, exist_ok=True)
    keys = [k.strip() for k in keys_str.split("\n") if k.strip()]
    with open(API_KEYS_FILE, "w") as f:
        json.dump(keys, f)

AVAILABLE_MODELS = [
    "Auto (Smart Fallback)",
    "gemini-3.5-flash",
    "gemini-3.0-flash",
    "gemini-2.5-pro",
    "gemini-2.5-flash",
    "gemini-2.0-flash",
    "gemini-1.5-flash"
]

def get_actual_model():
    """Get the currently selected model, resolving Auto mode."""
    if st.session_state.selected_model == "Auto (Smart Fallback)":
        # In Auto mode, use model_router to find available model
        return st.session_state.get("auto_resolved_model", MODEL_PRIORITY[0])
    return st.session_state.selected_model

_DEFAULTS: dict = {
    "region": list(_REGIONS.keys())[0],
    "location": _REGIONS[list(_REGIONS.keys())[0]][0],
    "gemini_api_keys": load_saved_api_keys(),
    "extracted_df": None,           # pd.DataFrame | None
    "resolved_images": None,        # list[tuple[str, bytes]] | None
    "uploaded_file_names": [],      # list[str]
    "save_success": False,
    "save_count": 0,
    "export_confirmed": False,
    "auto_model_index": 0,
    "auto_resolved_model": MODEL_PRIORITY[0],
    "model_router_state": ModelRouterState(),
    "chat_model_used": "",          # Track which model answered last
}

for key, val in _DEFAULTS.items():
    if key not in st.session_state:
        st.session_state[key] = val


# ══════════════════════════════════════════════════════════════════════════════
# Helper: Parse API keys
# ══════════════════════════════════════════════════════════════════════════════
def _get_api_keys() -> list[str]:
    """Parse non-empty API keys from the text area input."""
    raw = st.session_state.gemini_api_keys or ""
    keys = [k.strip() for k in raw.splitlines() if k.strip()]
    return keys


# ══════════════════════════════════════════════════════════════════════════════
# SIDEBAR — Configuration
# ══════════════════════════════════════════════════════════════════════════════
with st.sidebar:
    # Top logo & Title
    st.markdown("""
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.5rem; padding: 0 8px;">
            <div style="
                width: 32px; height: 32px; 
                background: linear-gradient(135deg, #7c3aed, #6d28d9);
                border-radius: 10px;
                display: flex; align-items: center; justify-content: center;
                box-shadow: 0 2px 8px rgba(124,58,237,0.3);
            ">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
            </div>
            <span style="font-size: 18px; font-weight: 600; color: #e8e8e8; letter-spacing: -0.02em;">Pricelist Scanner</span>
        </div>
    """, unsafe_allow_html=True)

    # ── Navigation ──
    st.caption("MENU")
    
    # Hide the default radio circles and adjust spacing
    st.markdown("""
        <style>
        /* Hide radio button circles (holes) using high specificity */
        div[data-testid="stRadio"] div[role="radiogroup"] > label > div:first-child {
            display: none !important;
        }
        
        /* Make the text container expand */
        div[data-testid="stRadio"] div[role="radiogroup"] > label > div:last-child {
            width: 100%;
        }

        /* Style the menu items */
        div[data-testid="stRadio"] div[role="radiogroup"] > label {
            padding: 10px 14px;
            margin-bottom: 6px;
            border-radius: 8px;
            background: rgba(255,255,255,0.03);
            border: 1px solid transparent;
            transition: all 0.2s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        
        /* Hover effect */
        div[data-testid="stRadio"] div[role="radiogroup"] > label:hover {
            background: rgba(255,255,255,0.08);
        }
        
        /* Highlight the selected item (Streamlit assigns aria-checked to the inner radio, but we can simulate checked by looking for the radio's checked state via CSS if possible, but actually Streamlit adds aria-checked="true" to the inner div. Let's just add active state based on background color or rely on Streamlit's text color changes). 
        To be safe, we'll use a hack to style the label when the input is checked, but Streamlit hides the input.
        Let's just use Streamlit's native selected text color, which is already different. */
        </style>
    """, unsafe_allow_html=True)
    
    app_mode_raw = st.radio(
        "Pilih Halaman",
        [":material/dashboard: Dashboard Input", ":material/smart_toy: Chatbot Analisis"],
        key="app_mode_radio",
        label_visibility="collapsed"
    )
    
    # Strip the icon part to get the logical page name
    app_mode = app_mode_raw.replace(":material/dashboard: ", "").replace(":material/smart_toy: ", "")

    st.divider()

    if app_mode == "Dashboard Input":
        st.markdown("<br>", unsafe_allow_html=True)
        st.caption("LOKASI KONTER")
        selected_region = st.selectbox(
            "Pilih Region",
            list(_REGIONS.keys()),
            index=list(_REGIONS.keys()).index(st.session_state.get("region", list(_REGIONS.keys())[0])),
            key="region_select",
        )
        st.session_state.region = selected_region
        
        region_locations = _REGIONS[selected_region]
        
        current_loc = st.session_state.get("location", region_locations[0])
        if current_loc not in region_locations:
            current_loc = region_locations[0]
            
        st.session_state.location = st.selectbox(
            "Pilih Lokasi",
            region_locations,
            index=region_locations.index(current_loc),
            key="location_select",
        )

    # ── Gemini API Keys ──
    st.markdown("<br>", unsafe_allow_html=True)
    st.caption("API KEYS")
    env_key = os.environ.get("GEMINI_API_KEY", "")

    existing_keys = [k.strip() for k in st.session_state.gemini_api_keys.split("\n") if k.strip()] if st.session_state.gemini_api_keys else []
    
    if "num_api_keys" not in st.session_state:
        st.session_state.num_api_keys = max(1, len(existing_keys))

    current_keys = []
    
    for i in range(st.session_state.num_api_keys):
        widget_key = f"api_key_input_dynamic_{i}"
        
        if widget_key not in st.session_state:
            if i < len(existing_keys):
                st.session_state[widget_key] = existing_keys[i]
            elif i == 0 and not existing_keys and env_key:
                st.session_state[widget_key] = env_key
            else:
                st.session_state[widget_key] = ""
                
        key_val = st.text_input(
            f"API Key {i+1}" if st.session_state.num_api_keys > 1 else "Gemini API Key", 
            type="password", 
            key=widget_key,
            help="Masukkan Gemini API key untuk OCR dan Chatbot." if i == 0 else None
        )
        if key_val and key_val.strip():
            current_keys.append(key_val.strip())

    st.session_state.gemini_api_keys = "\n".join(current_keys)
    save_api_keys(st.session_state.gemini_api_keys)

    if st.button("Tambah API Key", icon=":material/add:", use_container_width=True):
        st.session_state.num_api_keys += 1
        st.rerun()

    # ── Model Selection ──
    st.markdown("<br>", unsafe_allow_html=True)
    st.caption("MODEL AI")
    
    if "selected_model" not in st.session_state:
        st.session_state.selected_model = AVAILABLE_MODELS[0]
        
    st.session_state.selected_model = st.selectbox(
        "Pilih Versi Model Gemini",
        options=AVAILABLE_MODELS,
        index=AVAILABLE_MODELS.index(st.session_state.selected_model) if st.session_state.selected_model in AVAILABLE_MODELS else 0,
        key="model_select_box",
        label_visibility="collapsed"
    )

    # ── Quota Status Display (Auto mode) ──
    if st.session_state.selected_model == "Auto (Smart Fallback)" and current_keys:
        st.markdown("<br>", unsafe_allow_html=True)
        st.caption("STATUS QUOTA MODEL")
        
        # Auto-fetch quota details (model_router handles caching automatically)
        router_state = st.session_state.model_router_state
        details = get_quota_detail_all(
            api_keys=current_keys,
            router_state=router_state,
        )
        st.session_state._quota_details = details
        
        # Auto-resolve the best model
        for model in MODEL_PRIORITY:
            d = details.get(model, {})
            if d.get("status") == QuotaStatus.AVAILABLE:
                st.session_state.auto_resolved_model = model
                break

        # Display quota details using native Streamlit widgets
        for model in MODEL_PRIORITY:
            d = details.get(model, {})
            if not d:
                continue
            
            status = d.get("status", QuotaStatus.UNKNOWN)
            label = d.get("label", "")
            sub_label = d.get("sub_label", "")
            bar_pct = d.get("bar_pct", 0) / 100.0  # Streamlit progress expects 0.0 to 1.0
            is_active = (model == st.session_state.get("auto_resolved_model", ""))
            
            short_name = model.replace("gemini-", "")
            active_marker = " ◀ Active" if is_active else ""
            
            # Use native columns for a clean header
            col1, col2 = st.columns([1, 1])
            with col1:
                st.markdown(f"**{short_name}**")
            with col2:
                # Add color context to label based on status
                color = "green" if status == QuotaStatus.AVAILABLE else "red" if status == QuotaStatus.EXHAUSTED else "orange" if status == QuotaStatus.ERROR else "gray"
                st.markdown(f"<div style='text-align: right; color: {color}; font-size: 0.85em;'>{label}</div>", unsafe_allow_html=True)
            
            # Native progress bar
            st.progress(bar_pct)
            st.caption(f"{sub_label}{active_marker}")
            st.write("")  # Small spacing

    st.divider()

    # ── DB Stats ──
    st.markdown("<br>", unsafe_allow_html=True)
    st.caption("DATABASE")
    total = db.count()
    latest = db.get_latest_scan_date() or "-"
    st.metric("Total Records", f"{total:,}")
    st.caption(f"Scan terakhir: {latest}")


# ══════════════════════════════════════════════════════════════════════════════
# PAGE: DASHBOARD INPUT
# ══════════════════════════════════════════════════════════════════════════════
if app_mode == "Dashboard Input":
    # Page header with gradient
    st.markdown("""
        <div class="page-header">
            <h1>📊 Dashboard Input Harga</h1>
        </div>
    """, unsafe_allow_html=True)
    st.markdown(
        f"**Lokasi:** {st.session_state.location}  ·  "
        f"**Waktu:** {datetime.now().strftime('%d %b %Y, %H:%M')}  ·  "
        f"**Model:** `{get_actual_model()}`"
    )

    st.divider()

    # ══════════════════════════════════════════════════════════════════════════════
    # STEP 1 — Upload Files (multiple images / ZIP / mix)
    # ══════════════════════════════════════════════════════════════════════════════
    st.header("Upload File")

    uploaded_files = st.file_uploader(
        "Pilih gambar sachet atau file ZIP berisi gambar",
        type=["jpg", "jpeg", "png", "webp", "bmp", "tiff", "zip"],
        accept_multiple_files=True,
        key="file_uploader",
        help="Anda bisa upload banyak file sekaligus — gambar langsung atau ZIP. File non-gambar akan diabaikan.",
    )

    if uploaded_files:
        # Resolve all uploads into a flat list of images
        resolved = resolve_uploaded_files(uploaded_files)
        st.session_state.resolved_images = resolved
        st.session_state.uploaded_file_names = [uf.name for uf in uploaded_files]

        # Show summary
        n_uploads = len(uploaded_files)
        n_images = len(resolved)
        zip_count = sum(1 for uf in uploaded_files if uf.name.lower().endswith(".zip"))

        summary_parts = [f"**{n_uploads} file** diupload"]
        if zip_count > 0:
            summary_parts.append(f"({zip_count} ZIP diekstrak)")
        summary_parts.append(f"→ **{n_images} gambar** terdeteksi")
        st.success(" ".join(summary_parts))

        # Preview thumbnails
        if resolved:
            cols_per_row = min(len(resolved), 5)
            cols = st.columns(cols_per_row)
            for idx, (fname, img_bytes) in enumerate(resolved[:10]):  # show max 10
                with cols[idx % cols_per_row]:
                    st.image(img_bytes, caption=fname, use_container_width=True)
            if len(resolved) > 10:
                st.caption(f"... dan {len(resolved) - 10} gambar lainnya.")
    else:
        st.session_state.resolved_images = None

    st.divider()

    # ══════════════════════════════════════════════════════════════════════════════
    # STEP 2 — Preprocess + Extract via Gemini (with Smart Fallback)
    # ══════════════════════════════════════════════════════════════════════════════
    st.header("Preprocess & Ekstrak Data dengan AI")

    has_images = (
        st.session_state.resolved_images is not None
        and len(st.session_state.resolved_images) > 0
    )

    col_btn, col_info = st.columns([1, 3])
    with col_btn:
        extract_clicked = st.button(
            "Ekstrak Semua", icon=":material/auto_awesome:",
            use_container_width=True,
            type="primary",
            disabled=not has_images,
        )

    if extract_clicked and has_images:
        api_keys = _get_api_keys()
        if not api_keys:
            st.error("Masukkan minimal satu Gemini API Key di sidebar terlebih dahulu.")
        else:
            images = st.session_state.resolved_images
            n_total = len(images)
            all_packages: list[dict] = []
            key_index = 0
            router_state: ModelRouterState = st.session_state.model_router_state

            progress_bar = st.progress(0, text="Memulai preprocessing & ekstraksi...")
            status_container = st.empty()

            for idx, (fname, raw_bytes) in enumerate(images):
                progress_frac = (idx) / n_total
                progress_bar.progress(progress_frac, text=f"[{idx+1}/{n_total}] {fname}")

                # 2a. Preprocess
                status_container.info(f":material/sync: Preprocessing: **{fname}**...")
                try:
                    preprocessed = preprocess_image(raw_bytes)
                except ValueError as exc:
                    st.warning(f"Gagal preprocess `{fname}`: {exc}. Melewatkan...")
                    continue

                # 2b. Extract via Gemini with Smart Fallback
                status_container.info(f":material/smart_toy: Mengekstrak data dari: **{fname}**...")
                
                try:
                    if st.session_state.selected_model == "Auto (Smart Fallback)":
                        # Use smart_invoke for automatic fallback
                        def _extract_fn(api_key, model_name):
                            pkgs, _ = extract_packages_gemini(
                                image_bytes=preprocessed,
                                api_keys=[api_key],
                                key_index=0,
                                max_retries=2,
                                model=model_name,
                            )
                            return pkgs
                        
                        result, used_model, used_key = smart_invoke(
                            invoke_fn=_extract_fn,
                            api_keys=api_keys,
                            preferred_model=get_actual_model(),
                            router_state=router_state,
                            on_status=lambda msg: status_container.warning(msg),
                            on_model_switch=lambda old, new: status_container.info(
                                f":material/sync: Auto-switch: {old} → {new}"
                            ),
                        )
                        all_packages.extend(result)
                        st.session_state.auto_resolved_model = used_model
                    else:
                        # Direct model usage with retry
                        actual_model = get_actual_model()
                        packages, key_index = extract_packages_gemini(
                            image_bytes=preprocessed,
                            api_keys=api_keys,
                            key_index=key_index,
                            max_retries=len(api_keys) + 2,
                            model=actual_model,
                            on_status=lambda msg: status_container.warning(f"{msg}"),
                        )
                        all_packages.extend(packages)
                        
                except Exception as exc:
                    err_str = str(exc)
                    if "429" in err_str or "RESOURCE_EXHAUSTED" in err_str:
                        st.warning(
                            f":material/warning: Gagal `{fname}`: Quota habis. "
                            f"Gunakan mode **Auto (Smart Fallback)** untuk pindah model otomatis."
                        )
                    else:
                        st.warning(f"Gagal ekstraksi `{fname}`: {exc}")

            progress_bar.progress(1.0, text=":material/check_circle: Selesai!")
            status_container.empty()

            if not all_packages:
                st.warning("Tidak ada paket yang berhasil diekstrak dari semua gambar.")
                st.session_state.extracted_df = None
            else:
                # 2c. Clean, compute yield & category
                raw_df = pd.DataFrame(all_packages)
                df_clean = clean_dataframe(raw_df)

                # Add scan_date
                scan_date = datetime.now().isoformat()
                df_clean.insert(0, "scan_date", scan_date)

                st.session_state.extracted_df = df_clean
                st.session_state.save_success = False
                st.session_state.export_confirmed = False

                st.success(
                    f":material/check_circle: Berhasil mengekstrak **{len(df_clean)} paket** "
                    f"dari **{n_total} gambar**!"
                )

    st.divider()

    # ══════════════════════════════════════════════════════════════════════════════
    # STEP 3 — Human-in-the-Loop: Edit & Validate
    # ══════════════════════════════════════════════════════════════════════════════
    st.header("Review & Edit Data")

    if st.session_state.extracted_df is not None:
        df_display = st.session_state.extracted_df

        st.info(
            f"Lokasi: **{st.session_state.location}**  ·  "
            f"**{len(df_display)} paket** diekstrak  ·  "
            f"File: {', '.join(st.session_state.uploaded_file_names[:3])}"
            + ("..." if len(st.session_state.uploaded_file_names) > 3 else ""),
        )
        st.caption(
            "Periksa dan koreksi data di bawah ini. "
            "Klik sel untuk mengedit. Anda dapat menambah/menghapus baris."
        )

        # ── Editable table ───────────────────────────────────────────────────
        edited_df = st.data_editor(
            df_display,
            key="package_editor",
            num_rows="dynamic",
            use_container_width=True,
            column_config={
                "scan_date": st.column_config.TextColumn(
                    "Tanggal Scan",
                    disabled=True,
                    help="Diisi otomatis saat ekstraksi.",
                ),
                "provider": st.column_config.SelectboxColumn(
                    "Provider",
                    options=PROVIDER_CODES,
                    help="Kode operator: TSEL, IM3, 3ID, XL, AXIS, SF.",
                    required=True,
                ),
                "gb": st.column_config.NumberColumn(
                    "Kuota (GB)",
                    min_value=0.0,
                    format="%.1f",
                    help="Kuota data dalam GB.",
                    required=True,
                ),
                "days": st.column_config.NumberColumn(
                    "Masa Aktif (Hari)",
                    min_value=1,
                    step=1,
                    help="Masa aktif paket dalam hari.",
                    required=True,
                ),
                "price": st.column_config.NumberColumn(
                    "Harga (Rp)",
                    min_value=0,
                    step=1000,
                    format="Rp %d",
                    help="Harga dalam Rupiah.",
                    required=True,
                ),
                "yield_val": st.column_config.NumberColumn(
                    "Yield (Rp/GB)",
                    min_value=0,
                    step=1,
                    format="%d",
                    help="Yield = ceil(Price / GB). Auto-computed, bisa diedit manual.",
                ),
                "category": st.column_config.SelectboxColumn(
                    "Kategori",
                    options=CATEGORIES_ORDER,
                    help="Kategori otomatis berdasarkan masa aktif & harga.",
                    required=True,
                ),
            },
            hide_index=True,
        )

        # Sync edits back to session state
        st.session_state.extracted_df = edited_df

        # ── Recalculate yield & category button ──────────────────────────────
        if st.button("Hitung Ulang Yield & Kategori", icon=":material/sync:", help="Recalculate yield dan category dari data yang sudah diedit."):
            df_recalc = edited_df.copy()
            df_recalc["price"] = pd.to_numeric(df_recalc["price"], errors="coerce").fillna(0).astype(int)
            df_recalc["gb"] = pd.to_numeric(df_recalc["gb"], errors="coerce").fillna(0.0)
            df_recalc["days"] = pd.to_numeric(df_recalc["days"], errors="coerce").fillna(0).astype(int)
            df_recalc["yield_val"] = df_recalc.apply(
                lambda row: compute_yield(row["price"], row["gb"]), axis=1
            )
            from pipeline import categorize
            df_recalc["category"] = df_recalc.apply(
                lambda row: categorize(row["days"], row["price"]), axis=1
            )
            st.session_state.extracted_df = df_recalc
            st.rerun()

        st.divider()

        # ══════════════════════════════════════════════════════════════════════
        # STEP 4 — Simpan ke Database
        # ══════════════════════════════════════════════════════════════════════
        st.header("Simpan ke Database")

        col_save, col_clear = st.columns(2)

        with col_save:
            save_clicked = st.button(
                "Simpan ke Database", icon=":material/save:",
                use_container_width=True,
                type="primary",
            )

        with col_clear:
            clear_clicked = st.button(
                "Bersihkan Data", icon=":material/delete:",
                use_container_width=True,
            )

        if save_clicked:
            df_to_save = st.session_state.extracted_df
            if df_to_save is None or df_to_save.empty:
                st.warning("Tidak ada data untuk disimpan.")
            else:
                if df_to_save["provider"].isna().any() or (df_to_save["provider"] == "").any():
                    st.error("Kolom **Provider** tidak boleh kosong. Periksa kembali data Anda.")
                else:
                    try:
                        count = db.insert_packages(
                            df_to_save,
                            location=st.session_state.location,
                        )
                        st.session_state.save_success = True
                        st.session_state.save_count = count
                        st.rerun()
                    except Exception as exc:
                        st.error(f"Gagal menyimpan: {exc}")

        if clear_clicked:
            st.session_state.extracted_df = None
            st.session_state.resolved_images = None
            st.session_state.uploaded_file_names = []
            st.session_state.save_success = False
            st.session_state.export_confirmed = False
            st.rerun()

        if st.session_state.save_success:
            st.success(
                f":material/check_circle: **{st.session_state.save_count} paket** berhasil disimpan ke database!  "
                f"(Lokasi: {st.session_state.location})",
            )

        st.divider()

        # ══════════════════════════════════════════════════════════════════════
        # STEP 5 — Export to Excel (with confirmation)
        # ══════════════════════════════════════════════════════════════════════
        st.header("Export ke Excel")

        @st.dialog("Konfirmasi Export Excel")
        def _confirm_export_dialog():
            df_ex = st.session_state.extracted_df
            n_rows = len(df_ex) if df_ex is not None else 0
            n_providers = df_ex["provider"].nunique() if df_ex is not None else 0
            n_categories = df_ex["category"].nunique() if df_ex is not None else 0

            st.markdown(
                f"Anda akan mengekspor **{n_rows} paket** "
                f"dari **{n_providers} provider** "
                f"dalam **{n_categories} kategori** "
                f"ke format Excel.\n\n"
                f"Format: Layout horizontal per-provider dengan heatmap yield "
                f"dan highlight best-deal (border merah)."
            )

            col_yes, col_no = st.columns(2)
            with col_yes:
                if st.button("Ya, Export", use_container_width=True, type="primary"):
                    st.session_state.export_confirmed = True
                    st.rerun()
            with col_no:
                if st.button("Batal", use_container_width=True):
                    st.rerun()

        col_export, col_download = st.columns([1, 2])

        with col_export:
            if st.button("Export ke Excel", icon=":material/download:", use_container_width=True, type="primary"):
                _confirm_export_dialog()

        # Generate and show download button after confirmation
        if st.session_state.export_confirmed:
            with st.spinner("Generating Excel report..."):
                df_export = st.session_state.extracted_df
                excel_bytes = generate_excel(df_export)
                timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
                filename = f"Dashboard_Input_Harga_{st.session_state.location}_{timestamp}.xlsx"

            with col_download:
                st.download_button(
                    label="Download Excel", icon=":material/description:",
                    data=excel_bytes,
                    file_name=filename,
                    mime="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                    use_container_width=True,
                    type="primary",
                )
            st.success(f"File **{filename}** siap diunduh!")

    else:
        st.info(
            "Belum ada data yang diekstrak. Upload file dan klik **Ekstrak Semua** di atas."
        )

    # ══════════════════════════════════════════════════════════════════════════════
    # FOOTER — Riwayat Data Terbaru
    # ══════════════════════════════════════════════════════════════════════════════
    st.divider()

    with st.expander(":material/history: Riwayat Data Tersimpan (20 terakhir)", expanded=False):
        recent = db.query(
            "SELECT id, scan_date, location, provider, gb, days, price, yield_val, category "
            "FROM package_history ORDER BY id DESC LIMIT 20;"
        )
        if recent.empty:
            st.caption("Belum ada data di database.")
        else:
            # Hide native Streamlit dataframe toolbar to avoid CSV confusion
            st.markdown(
                '<style>[data-testid="stElementToolbar"] {display: none !important;}</style>', 
                unsafe_allow_html=True
            )
            
            # Custom Excel download for history
            try:
                hist_excel = generate_excel(recent)
                st.download_button(
                    label="Download Riwayat ke Excel", icon=":material/description:",
                    data=hist_excel,
                    file_name=f"Riwayat_Harga_{datetime.now().strftime('%Y%m%d')}.xlsx",
                    mime="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                    type="primary"
                )
            except Exception as e:
                st.error(f"Gagal generate Excel riwayat: {e}")
                
            st.dataframe(recent, use_container_width=True, hide_index=True)


# ══════════════════════════════════════════════════════════════════════════════
# PAGE: CHATBOT ANALISIS (Agentic RAG) — Premium UI
# ══════════════════════════════════════════════════════════════════════════════
elif app_mode == "Chatbot Analisis":
    
    # ── Chat Header with Model Badge ──
    current_model = get_actual_model()
    model_badge_class = "model-badge-active"
    
    header_col1, header_col2 = st.columns([3, 1])
    with header_col1:
        st.markdown("""
            <div class="page-header">
                <h1>💬 Chatbot Analisis AI</h1>
            </div>
        """, unsafe_allow_html=True)
        st.markdown(
            "Tanya apa saja tentang data harga paket internet. "
            "AI akan menerjemahkan pertanyaan Anda menjadi **SQL query** untuk menganalisis tren dari database."
        )
    with header_col2:
        model_display = current_model
        if st.session_state.selected_model == "Auto (Smart Fallback)":
            model_display = f"Auto → {current_model}"
        st.markdown(f"""
            <div style="text-align: right; padding-top: 12px;">
                <span class="model-badge {model_badge_class}">
                    :material/bolt: {model_display}
                </span>
            </div>
        """, unsafe_allow_html=True)
    
    st.divider()
    
    # Check for API Key
    api_keys = _get_api_keys()
    if not api_keys:
        st.markdown("""
            <div class="chat-welcome">
                <h2>🔑 API Key Diperlukan</h2>
                <p>Masukkan Gemini API Key di sidebar untuk mulai menggunakan Chatbot Analisis.</p>
            </div>
        """, unsafe_allow_html=True)
        st.stop()

    # Initialize chat history
    if "messages" not in st.session_state:
        st.session_state.messages = []

    # Show welcome screen if no messages
    if not st.session_state.messages:
        st.markdown("""
            <div class="chat-welcome">
                <h2>Halo! Saya Analis Harga 👋</h2>
                <p>Saya bisa menganalisis data harga paket internet dari database. 
                Tanyakan tentang perbandingan harga, tren, atau cari paket terbaik.</p>
            </div>
        """, unsafe_allow_html=True)
        
        # Suggestion chips as buttons
        st.markdown("<br>", unsafe_allow_html=True)
        
        chip_cols = st.columns(2)
        suggestions = [
            "Provider apa saja yang ada di database?",
            "Paket termurah dari Telkomsel?",
            "Rata-rata harga paket MONTHLY 30-50K?",
            "Paket dengan kuota terbesar?",
        ]
        
        for i, suggestion in enumerate(suggestions):
            with chip_cols[i % 2]:
                if st.button(suggestion, key=f"suggestion_{i}", use_container_width=True, icon=[":material/bar_chart:", ":material/payments:", ":material/trending_up:", ":material/emoji_events:"][i]):
                    # Remove emoji prefix for the actual query
                    query = suggestion
                    st.session_state.messages.append({"role": "user", "content": query})
                    st.session_state._pending_query = query
                    st.rerun()

    # Display chat messages from history
    for message in st.session_state.messages:
        with st.chat_message(message["role"]):
            st.markdown(message["content"])
            # Show model badge for assistant messages
            if message["role"] == "assistant" and "model_used" in message:
                st.caption(f":material/bolt: Model: {message['model_used']}")

    # Handle pending query from suggestion chips
    pending_query = st.session_state.pop("_pending_query", None)
    
    # Accept user input
    prompt = st.chat_input("Tanya tentang data harga paket internet...")
    
    # Use pending query if no new input
    if not prompt and pending_query:
        prompt = pending_query
    
    if prompt:
        # Add user message to chat history (if not already added by suggestion)
        if not pending_query:
            st.session_state.messages.append({"role": "user", "content": prompt})
        
        # Display user message
        with st.chat_message("user"):
            st.markdown(prompt)

        # Display assistant response
        with st.chat_message("assistant"):
            # Typing indicator
            typing_placeholder = st.empty()
            typing_placeholder.markdown("""
                <div class="typing-indicator">
                    <span></span><span></span><span></span>
                    <span style="margin-left: 8px; font-size: 13px; color: rgba(255,255,255,0.4); animation: none; width: auto; height: auto; background: none;">
                        Menganalisis database...
                    </span>
                </div>
            """, unsafe_allow_html=True)
            
            try:
                from chatbot import invoke_with_smart_fallback, get_chatbot_agent
                
                router_state: ModelRouterState = st.session_state.model_router_state
                status_placeholder = st.empty()
                
                if st.session_state.selected_model == "Auto (Smart Fallback)":
                    # Use smart fallback
                    answer, used_model, used_key = invoke_with_smart_fallback(
                        prompt=prompt,
                        api_keys=api_keys,
                        preferred_model=get_actual_model(),
                        router_state=router_state,
                        on_status=lambda msg: status_placeholder.caption(f":material/sync: {msg}"),
                        on_model_switch=lambda old, new: status_placeholder.info(
                            f":material/sync: Auto-switch: **{old}** → **{new}**"
                        ),
                    )
                    st.session_state.auto_resolved_model = used_model
                    st.session_state.chat_model_used = used_model
                else:
                    # Direct model usage
                    used_model = get_actual_model()
                    agent = get_chatbot_agent(
                        api_key=api_keys[0],
                        model_name=used_model,
                    )
                    response = agent.invoke({"input": prompt})
                    answer = response.get("output", "Maaf, saya tidak dapat memproses pertanyaan tersebut.")
                    
                    # Parse raw output format
                    if isinstance(answer, list) and len(answer) > 0 and isinstance(answer[0], dict) and "text" in answer[0]:
                        answer = answer[0]["text"]
                    elif isinstance(answer, str) and answer.strip().startswith("[{'type':"):
                        import ast
                        try:
                            parsed = ast.literal_eval(answer)
                            if isinstance(parsed, list) and len(parsed) > 0 and isinstance(parsed[0], dict) and "text" in parsed[0]:
                                answer = parsed[0]["text"]
                        except Exception:
                            pass
                    
                    st.session_state.chat_model_used = used_model
                
                # Clear typing indicator and status
                typing_placeholder.empty()
                status_placeholder.empty()
                
                # Display answer
                st.markdown(answer)
                st.caption(f":material/bolt: Model: {used_model}")
                
                # Add to history
                st.session_state.messages.append({
                    "role": "assistant",
                    "content": answer,
                    "model_used": used_model,
                })
                    
            except Exception as e:
                typing_placeholder.empty()
                err_str = str(e)
                
                if "429" in err_str or "RESOURCE_EXHAUSTED" in err_str or "Quota" in err_str:
                    if st.session_state.selected_model != "Auto (Smart Fallback)":
                        error_msg = (
                            ":material/warning: **Quota model habis!**\n\n"
                            f"Model `{get_actual_model()}` telah kehabisan quota. "
                            "Pilih **Auto (Smart Fallback)** di sidebar untuk otomatis pindah ke model lain."
                        )
                    else:
                        error_msg = (
                            ":material/warning: **Semua model kehabisan quota.**\n\n"
                            "Silakan tunggu beberapa saat atau tambahkan API key baru di sidebar."
                        )
                    st.error(error_msg)
                else:
                    error_msg = f":material/error: Maaf, terjadi kesalahan: {err_str}"
                    st.error(error_msg)
                
                st.session_state.messages.append({
                    "role": "assistant",
                    "content": error_msg,
                    "model_used": "error",
                })
