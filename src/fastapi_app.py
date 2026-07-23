from fastapi import FastAPI, File, UploadFile, HTTPException, Form
from fastapi.responses import JSONResponse
import uvicorn
from typing import List
import json
import logging

# Import existing pipeline functions
from pipeline import preprocess_image, extract_packages_gemini, generate_excel, generate_summary_insights, is_zip_file, extract_from_zip
import pandas as pd
from pydantic import BaseModel
from fastapi.responses import JSONResponse, Response

app = FastAPI(title="Pricelist Scanner API")
logging.basicConfig(level=logging.INFO)

@app.post("/api/extract")
async def extract_data(
    files: List[UploadFile] = File(...),
    api_keys: str = Form(...),
    model: str = Form("gemini-2.0-flash"),
    prompt: str = Form(None),
    pricelist_id: int = Form(None)
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
                    processed_bytes = preprocess_image(img_bytes)
                    processed_images.append(processed_bytes)
            else:
                processed_bytes = preprocess_image(file_bytes)
                processed_images.append(processed_bytes)
                
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
            if pricelist_id is not None:
                try:
                    requests.post(
                        f"http://127.0.0.1:8002/api/scanner/{pricelist_id}/status",
                        json={"status": msg},
                        timeout=3
                    )
                except Exception as e:
                    logging.warning(f"Failed to push status to webhook: {e}")

        # Call Gemini via our pipeline
        data, _ = extract_packages_gemini(
            image_bytes_list=processed_images,
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
    price: int
    gb: float
    days: int
    yield_val: float
    category: str
    image_timestamp: str = None
    image_location: str = None

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
        for m in client.models.list_models():
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
        
        # Format context data
        df = pd.DataFrame([p.dict() for p in req.packages])
        context_str = df.to_string(index=False)
        
        prompt = f"""
Anda adalah Data Analyst assistant.
Data saat ini:
{context_str}

Pertanyaan User: {req.message}

Jawab dengan ramah, singkat, dan tepat.
Jika pertanyaan berkaitan dengan grafik/visualisasi, Anda BISA mengembalikan konfigurasi Chart.js di dalam block ```json ... ```.

Contoh JSON Chart.js:
```json
{{
  "type": "bar",
  "data": {{ "labels": ["A", "B"], "datasets": [{{ "label": "Harga", "data": [10, 20] }}] }},
  "options": {{ "responsive": true }}
}}
```

Jawaban Anda:
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
        
        # Extract JSON if exists (more robust regex)
        import re
        match = re.search(r'```(?:json)?\s*([\s\S]*?)\s*```', text, re.IGNORECASE)
        if match:
            try:
                chart_config = json.loads(match.group(1))
                text = text.replace(match.group(0), "").strip()
            except:
                pass
                
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

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8081)
