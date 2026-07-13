"""
chatbot.py — LangChain Agentic RAG for Text-to-SQL with Smart Model Fallback

This module defines the Chatbot interface using LangChain.
It sets up a connection to `package_history.db` and uses Gemini
to translate natural language (Indonesian) into SQL queries to analyze data.

Features:
    - Smart model fallback via model_router
    - Automatic retry on quota exhaustion
    - Multi-API-key support
"""

from langchain_community.agent_toolkits import create_sql_agent
from langchain_community.utilities import SQLDatabase
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.messages import SystemMessage

from model_router import (
    ModelRouterState,
    QuotaStatus,
    smart_invoke,
    MODEL_PRIORITY,
)

# Database URI (SQLite)
DB_URI = "sqlite:///data/package_history.db"

# Agent System Prompt
SYSTEM_PROMPT = """Anda adalah analis harga paket internet. Tugas Anda menganalisis tren harga antar konter dan waktu berdasarkan database SQLite.

Berikan jawaban dalam Bahasa Indonesia yang profesional dan mudah dipahami.
Jika ditanya tentang perbandingan, analisislah data dari tabel package_history.

Skema Tabel `package_history`:
- id (INTEGER): Primary Key
- scan_date (DATETIME): Waktu scan/upload
- location (VARCHAR): Lokasi konter (misal: Jakarta, Surabaya)
- provider (VARCHAR): Operator (TSEL, IM3, 3ID, XL, AXIS, SF)
- gb (FLOAT): Kuota dalam Gigabyte
- days (INTEGER): Masa aktif dalam hari
- price (INTEGER): Harga dalam Rupiah
- yield_val (INTEGER): Nilai Yield (Rp/GB) = ceil(price / gb)
- category (VARCHAR): Kategori paket (misal: SACHET 1D-2D, MONTHLY 30-50K)

Gunakan kueri SQL untuk menjawab pertanyaan. Jangan membuat asumsi data jika tidak ada di tabel. Jika data kosong, sampaikan dengan sopan.
"""

def get_chatbot_agent(api_key: str, model_name: str = "gemini-3.5-flash"):
    """
    Initializes and returns a LangChain SQL Agent configured for Gemini.
    
    Args:
        api_key (str): The Gemini API key.
        model_name (str): The Gemini model to use.
        
    Returns:
        AgentExecutor: The LangChain agent executor.
    """
    if not api_key:
        raise ValueError("API Key Gemini diperlukan untuk menjalankan Chatbot.")

    # 1. Connect to SQLite Database
    db = SQLDatabase.from_uri(DB_URI)
    
    # 2. Configure LLM
    llm = ChatGoogleGenerativeAI(
        model=model_name,
        google_api_key=api_key,
        temperature=0, # Low temperature for accurate SQL generation
    )
    
    # 3. Create SQL Agent
    # We use 'tool-calling' agent_type which utilizes Gemini's native function calling.
    # This prevents the 'OUTPUT_PARSING_FAILURE' completely since it doesn't rely on string matching.
    agent_executor = create_sql_agent(
        llm=llm,
        toolkit=None, 
        db=db,
        agent_type="tool-calling",
        verbose=True, 
        max_iterations=15,
        early_stopping_method="generate",
        prefix=SYSTEM_PROMPT,
    )
    
    return agent_executor


def invoke_with_smart_fallback(
    prompt: str,
    api_keys: list[str],
    preferred_model: str | None = None,
    router_state: ModelRouterState | None = None,
    on_status=None,
    on_model_switch=None,
) -> tuple[str, str, str]:
    """
    Invoke the chatbot agent with automatic model fallback.
    
    Uses smart_invoke from model_router to automatically retry with
    different models when quota is exhausted.
    
    Args:
        prompt (str): The user's question.
        api_keys (list[str]): List of Gemini API keys.
        preferred_model (str | None): Preferred model to try first.
        router_state (ModelRouterState | None): Shared router state.
        on_status (callable | None): Status callback.
        on_model_switch (callable | None): Model switch callback.
        
    Returns:
        tuple[str, str, str]: (answer_text, used_model, used_api_key)
    """
    
    def _invoke_fn(api_key: str, model_name: str):
        """Inner function called by smart_invoke."""
        agent = get_chatbot_agent(api_key=api_key, model_name=model_name)
        response = agent.invoke({"input": prompt})
        answer = response.get("output", "Maaf, saya tidak dapat memproses pertanyaan tersebut.")
        
        # Parse raw output format if necessary
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
        
        return answer
    
    result, used_model, used_api_key = smart_invoke(
        invoke_fn=_invoke_fn,
        api_keys=api_keys,
        preferred_model=preferred_model,
        router_state=router_state,
        on_status=on_status,
        on_model_switch=on_model_switch,
        max_retries_per_model=2,
    )
    
    return (result, used_model, used_api_key)
