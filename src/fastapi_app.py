from fastapi import FastAPI, File, UploadFile, HTTPException, Form
from fastapi.responses import JSONResponse
import uvicorn
from typing import List
import json
import logging
import os
import time

# Import existing pipeline functions
from pipeline import preprocess_image, extract_packages_gemini, generate_excel, generate_summary_insights, is_zip_file, extract_from_zip, extract_metadata
import pandas as pd
from pydantic import BaseModel
from fastapi.responses import JSONResponse, Response
from google import genai

app = FastAPI(title="Pricelist Scanner API")
logging.basicConfig(level=logging.INFO)

# Where this service listens. The desktop app passes NATIVEPHP_FASTAPI_PORT so the
# bundled engine does not collide with a dev server or another service on 8081.
FASTAPI_PORT = int(os.getenv("NATIVEPHP_FASTAPI_PORT", os.getenv("FASTAPI_PORT", "8091")))

# Where to call Laravel back. Desktop runs Laravel on a port NativePHP picks, and
# Docker runs it under a different host, so this must not be hardcoded.
LARAVEL_URL = os.getenv("LARAVEL_URL", "http://127.0.0.1:8000").rstrip("/")

@app.post("/api/extract")
async def extract_data(
    files: List[UploadFile] = File(...),
    api_keys: str = Form(...),
    model: str = Form("gemini-2.0-flash"),
    prompt: str = Form(None),
    pricelist_id: int = Form(None),
    webhook_url: str = Form(None)
):
    """
    Endpoint for Laravel to send images and API key to.
    Returns the parsed JSON data from Gemini Multi-Agent.
    """
    try:
        processed_images = []
        for file in files:
            file_bytes = await file.read()
            
            if is_zip_file(file.filename, file_bytes):
                # Ekstrak gambar dari ZIP
                extracted = extract_from_zip(file_bytes)
                for name, img_bytes in extracted:
                    metadata_str = extract_metadata(name, img_bytes)
                    processed_bytes = preprocess_image(img_bytes)
                    processed_images.append((processed_bytes, metadata_str, name))
            else:
                metadata_str = extract_metadata(file.filename, file_bytes)
                processed_bytes = preprocess_image(file_bytes)
                processed_images.append((processed_bytes, metadata_str, file.filename))
                
        if not processed_images:
            raise ValueError("Tidak ada gambar valid yang ditemukan di dalam file upload.")
            
        keys_list = [k.strip() for k in api_keys.split(",") if k.strip()]
        if not keys_list:
            raise ValueError("Tidak ada API key yang valid.")
            
        models_list = [m.strip() for m in model.split(",") if m.strip()]
        if not models_list:
            models_list = ["gemini-2.0-flash"]

        import requests
        def status_callback(msg: str):
            if webhook_url:
                try:
                    requests.post(
                        webhook_url,
                        json={"status": msg},
                        timeout=3
                    )
                except Exception as e:
                    logging.warning(f"Failed to push status to webhook: {e}")
            elif pricelist_id is not None:
                try:
                    requests.post(
                        f"{LARAVEL_URL}/api/scanner/{pricelist_id}/status",
                        json={"status": msg},
                        timeout=3
                    )
                except Exception as e:
                    logging.warning(f"Failed to push status to webhook: {e}")

        # Call Gemini via our pipeline
        data, _ = extract_packages_gemini(
            image_data_list=processed_images,
            api_keys=keys_list,
            models=models_list,
            custom_prompt=prompt,
            on_status=status_callback
        )
        
        return JSONResponse(content={"status": "success", "data": data})

    except Exception as e:
        logging.error(f"Extraction failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

class PackageItem(BaseModel):
    provider: str
    package_name: str = ""
    price: int
    gb: float
    days: int
    yield_val: float
    category: str
    image_timestamp: str = None
    image_location: str = None
    image_filename: str = None

class ExportRequest(BaseModel):
    packages: List[PackageItem]

@app.post("/api/export")
async def export_excel(req: ExportRequest):
    try:
        # Convert to DataFrame
        df = pd.DataFrame([p.dict() for p in req.packages])
        
        # Apply pipeline's clean_dataframe to recalculate yield and re-categorize into SACHET / MONTHLY categories
        from pipeline import clean_dataframe
        df = clean_dataframe(df)
        
        # Generate Excel bytes
        excel_bytes = generate_excel(df)
        
        return Response(
            content=excel_bytes,
            media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            headers={"Content-Disposition": "attachment; filename=Rekap_Harga.xlsx"}
        )
    except Exception as e:
        logging.error(f"Export failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/api/insights")
async def fetch_insights(req: ExportRequest):
    try:
        df = pd.DataFrame([p.dict() for p in req.packages])
        insights = generate_summary_insights(df)
        return JSONResponse(content={"status": "success", "data": insights})
    except Exception as e:
        logging.error(f"Insights failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

class ChatRequest(BaseModel):
    message: str
    packages: List[PackageItem]
    api_keys: str
    model: str = "gemini-2.0-flash"

class CheckRequest(BaseModel):
    api_key: str

@app.post("/api/keys/check")
async def check_api_key(req: CheckRequest):
    """
    Validates the API key and returns a list of supported models.
    """
    try:
        client = genai.Client(api_key=req.api_key)
        # We want to list models and check if they are suitable for generateContent
        supported_models = []
        for m in client.models.list():
            # Filter models containing gemini in their name and supporting text/image generation
            name = m.name.replace("models/", "")
            if "gemini" in name:
                supported_models.append(name)
        
        # Deduplicate and sort
        supported_models = list(set(supported_models))
        
        return JSONResponse(content={
            "status": "success", 
            "supported_models": supported_models
        })
    except Exception as e:
        return JSONResponse(status_code=400, content={
            "status": "error",
            "message": str(e)
        })

@app.post("/api/chat")
async def chat_with_data(req: ChatRequest):
    try:
        from google import genai
        from google.genai import types
        import time

        keys_list = [k.strip() for k in req.api_keys.split(",") if k.strip()]
        if not keys_list:
            raise ValueError("Tidak ada API key yang valid.")
            
        models_list = [m.strip() for m in req.model.split(",") if m.strip()]
        if not models_list:
            models_list = ["gemini-2.0-flash"]
        
        # Get available columns
        df = pd.DataFrame([p.dict() for p in req.packages])
        available_columns = ", ".join(df.columns.tolist())
        # Format data as a minimal JSON string to save tokens but provide full context
        minimal_packages = []
        for p in req.packages:
            minimal_packages.append({
                "prov": p.provider,
                "name": p.package_name,
                "price": p.price,
                "gb": p.gb,
                "days": p.days,
                "yield": p.yield_val,
                "cat": p.category
            })
        data_json = json.dumps(minimal_packages, separators=(',', ':'))
        
        prompt = f"""
Anda adalah asisten Data Analyst Senior untuk Pricelist Internet. 
Tugas Anda adalah membaca data paket internet di bawah ini dan menjawab pertanyaan user dengan CERDAS, ANALITIS, dan AKURAT berdasarkan data tersebut.

Data Paket Internet (JSON):
{data_json}

Keterangan Kolom Data:
- prov: Provider
- name: Nama Paket
- price: Harga dalam Rupiah
- gb: Kuota dalam Gigabyte
- days: Masa Aktif dalam hari
- yield: Nilai Yield (Harga/GB. Semakin KECIL nilainya, semakin MURAH/WORTH IT per GB-nya).
- cat: Kategori Paket

Pertanyaan User: {req.message}

PENTING: Jawab SELALU dengan format JSON di dalam blok ```json ... ```.

Format JSON yang diizinkan:
{{
  "action": "chart" | "text",
  "text": "Jawaban analisis Anda.",
  "group_by": "provider" | "category" | "price" | "gb" | "days", 
  "metric": "count" | "average_price",
  "chart_type": "pie" | "bar" | "line" | "doughnut",
  "title": "Judul Grafik"
}}

ATURAN STRUKTUR JAWABAN (WAJIB DIIKUTI DI DALAM FIELD "text"):
Setiap kali menjawab pertanyaan tentang data, isi field "text" HARUS DIBAGI KETAT MENJADI 3 BAGIAN berikut:

📌 *Statement:*
(Pernyataan utama / kesimpulan singkat dan langsung yang menjawab pertanyaan user).

📊 *Bukti Berdasarkan Data:*
(Fakta & angka spesifik dari data. Sebutkan Provider, Nama Paket, Harga (Rp), Kuota (GB), Masa Aktif (Hari), dan Nilai Yield untuk membuktikan statement di atas).

💡 *Insight & Rekomendasi:*
(Analisis mendalam mengenai temuan data ini, perbandingan antar brand/kategori, serta saran atau poin keputusan bisnis yang bermanfaat).

Aturan Emas Tambahan:
1. Jika user HANYA BERTANYA, SET action="text", dan tulis 3 bagian struktur di atas pada field "text". JANGAN hasilkan grafik kecuali diminta secara eksplisit.
2. Jika user SECARA EKSPLISIT meminta grafik/visualisasi, barulah set action="chart" dan isi konfigurasi grafik.
"""
        config = types.GenerateContentConfig(temperature=0.0)
        
        response = None
        current_idx = 0
        max_attempts = len(keys_list) * len(models_list)
        
        for attempt in range(max_attempts):
            model_idx = attempt // len(keys_list)
            current_model = models_list[model_idx]
            
            try:
                client = genai.Client(api_key=keys_list[current_idx])
                response = client.models.generate_content(
                    model=current_model,
                    contents=prompt,
                    config=config,
                )
                break
            except Exception as e:
                error_msg = str(e)
                if "503" in error_msg or "UNAVAILABLE" in error_msg or "429" in error_msg or "RESOURCE_EXHAUSTED" in error_msg:
                    current_idx = (current_idx + 1) % len(keys_list)
                    time.sleep(1)
                elif "404" in error_msg or "NOT_FOUND" in error_msg or "403" in error_msg or "PERMISSION_DENIED" in error_msg:
                    current_idx = (current_idx + 1) % len(keys_list)
                else:
                    logging.error(f"Chat error: {error_msg}")
                    current_idx = (current_idx + 1) % len(keys_list)
                    
        if not response:
            raise RuntimeError("Gagal mendapatkan respons dari Gemini setelah merotasi seluruh API key.")
        
        text = response.text
        chart_config = None
        
        # Extract JSON instruction from LLM
        import re
        
        try:
            match = re.search(r'```(?:json)?\s*([\s\S]*?)\s*```', text, re.IGNORECASE)
            raw_str = match.group(1).strip() if match else text.strip()
            
            intent = json.loads(raw_str)
            if isinstance(intent, dict):
                text = intent.get("text", text)
                if intent.get("action") == "chart":
                    group_col = intent.get("group_by")
                    metric = intent.get("metric", "count")
                    chart_type = intent.get("chart_type", "bar")
                    title = intent.get("title", "Visualisasi Data")
                    
                    if group_col in df.columns:
                        # Calculate locally via Pandas
                        if metric == "count":
                            series = df[group_col].value_counts()
                            label_name = "Jumlah Paket"
                        elif metric == "average_price":
                            series = df.groupby(group_col)['price'].mean().round()
                            label_name = "Rata-rata Harga (Rp)"
                        else:
                            series = df[group_col].value_counts()
                            label_name = "Jumlah"
                            
                        data_dict = series.to_dict()
                        labels = [str(k) for k in data_dict.keys()]
                        values = list(data_dict.values())
                        
                        # Generate Chart.js JSON structure
                        chart_config = {
                            "type": chart_type,
                            "data": {
                                "labels": labels,
                                "datasets": [{
                                    "label": label_name,
                                    "data": values,
                                    "borderWidth": 1
                                }]
                            },
                            "options": {
                                "responsive": True,
                                "plugins": {
                                    "title": {
                                        "display": True,
                                        "text": title
                                    }
                                }
                            }
                        }
        except Exception as e:
            logging.warning(f"Intent parsing exception: {e}")

        # Fail-safe unwrap loop: guarantee text is never a JSON string or JSON codeblock
        for _ in range(3):
            if isinstance(text, str):
                s = text.strip()
                if s.startswith('{') or '```' in s:
                    clean_s = re.sub(r'```(?:json)?\s*([\s\S]*?)\s*```', r'\1', s, flags=re.IGNORECASE).strip()
                    try:
                        p = json.loads(clean_s)
                        if isinstance(p, dict) and "text" in p:
                            text = p["text"]
                        elif isinstance(p, str):
                            text = p
                        else:
                            break
                    except Exception:
                        break
                else:
                    break
            else:
                break
        return JSONResponse(content={
            "status": "success", 
            "data": {
                "text": text,
                "chart_config": chart_config
            }
        })
    except Exception as e:
        logging.error(f"Chat failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

class SimAgeRequest(BaseModel):
    provider: str
    phone_number: str

def check_provider_api(provider: str, phone_number: str) -> int:
    """
    Mock function to represent the reverse-engineered API call to the provider.
    Returns the age of the SIM card in days.
    """
    import requests
    provider = provider.upper().strip()
    
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        # "Authorization": "Bearer ...", 
        # "X-API-Key": "..." 
    }
    
    age_in_days = 0
    
    try:
        if provider == "XL" or provider == "AXIS":
            # url = "https://api.xl.co.id/v1/check-number"
            # payload = {"msisdn": phone_number}
            # response = requests.post(url, json=payload, headers=headers)
            # data = response.json()
            # age_in_days = data.get("age_in_days", 0)
            age_in_days = 45 # Mock babycare
            pass
        elif provider == "TSEL" or provider == "TELKOMSEL":
            # url = "https://api.telkomsel.com/v1/profile"
            # ...
            age_in_days = 120 # Mock non-babycare
            pass
        elif provider in ["IM3", "INDOSAT", "3ID", "3"]:
            # ...
            age_in_days = 90 # Boundary
            pass
        else:
            # Default fallback for unhandled providers
            age_in_days = -1
            
        return age_in_days
    except Exception as e:
        logging.error(f"Failed to check SIM age for {phone_number} ({provider}): {str(e)}")
        return -1

@app.post("/api/check-sim-age")
async def check_sim_age(req: SimAgeRequest):
    try:
        age_days = check_provider_api(req.provider, req.phone_number)
        
        if age_days < 0:
            status = "Unknown"
            product_type = "Unknown"
        else:
            if age_days < 90:
                product_type = "Perdana (Babycare)"
                status = "Active"
            else:
                product_type = "Isi Ulang (Non-Babycare)"
                status = "Active"
                
        return JSONResponse(content={
            "status": "success",
            "data": {
                "phone_number": req.phone_number,
                "provider": req.provider,
                "age_in_days": age_days,
                "product_type": product_type
            }
        })
    except Exception as e:
        logging.error(f"Check SIM age failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=FASTAPI_PORT)
