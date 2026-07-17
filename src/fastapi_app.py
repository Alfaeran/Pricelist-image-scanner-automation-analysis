from fastapi import FastAPI, File, UploadFile, HTTPException, Form
from fastapi.responses import JSONResponse
import uvicorn
from typing import List
import json
import logging

# Import existing pipeline functions
from pipeline import preprocess_image, extract_packages_gemini, generate_excel, generate_summary_insights
import pandas as pd
from pydantic import BaseModel
from fastapi.responses import JSONResponse, Response

app = FastAPI(title="Pricelist Scanner API")
logging.basicConfig(level=logging.INFO)

@app.post("/api/extract")
async def extract_data(
    files: List[UploadFile] = File(...),
    api_key: str = Form(...),
    model: str = Form("gemini-2.0-flash"),
    prompt: str = Form(None)
):
    """
    Endpoint for Laravel to send images and API key to.
    Returns the parsed JSON data from Gemini Multi-Agent.
    """
    try:
        processed_images = []
        for file in files:
            image_bytes = await file.read()
            processed_bytes = preprocess_image(image_bytes)
            processed_images.append(processed_bytes)
            
        # Call Gemini via our pipeline
        data, _ = extract_packages_gemini(
            image_bytes_list=processed_images,
            api_keys=[api_key],
            model=model,
            custom_prompt=prompt
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
    api_key: str

@app.post("/api/chat")
async def chat_with_data(req: ChatRequest):
    try:
        from google import genai
        from google.genai import types

        client = genai.Client(api_key=req.api_key)
        
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
        response = client.models.generate_content(
            model="gemini-3.5-flash",
            contents=prompt,
        )
        
        text = response.text
        chart_config = None
        
        # Extract JSON if exists
        import re
        match = re.search(r'```json\n([\s\S]*?)\n```', text)
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
    uvicorn.run(app, host="0.0.0.0", port=8001)
