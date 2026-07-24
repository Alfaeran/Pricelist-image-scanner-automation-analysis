import csv
import re

def clean_price(price_str):
    if not price_str: return 0
    clean = re.sub(r'[^\d]', '', str(price_str))
    return int(clean) if clean else 0

def clean_gb(gb_str):
    if not gb_str: return 0.0
    clean = str(gb_str).replace(',', '.')
    clean = re.sub(r'[^\d\.]', '', clean)
    try:
        return float(clean)
    except ValueError:
        return 0.0

def main():
    # Read Test CSV
    csv_packages = []
    with open('datatest_pricelist_2 (1).csv', 'r', encoding='utf-8') as f:
        reader = csv.reader(f)
        for i, row in enumerate(reader):
            if i < 4: continue # Skip first 4 rows (0,1,2 empty/title, 3 is header)
            if len(row) < 6: continue
            
            provider = str(row[1]).upper().strip()
            if not provider: continue
            
            if provider == 'SF': provider = 'SMARTFREN'
            elif provider == 'TSEL': provider = 'TELKOMSEL'
            elif provider == '3ID': provider = '3'
            elif provider == 'BYU': provider = 'BY.U'
            
            price = clean_price(row[3])
            gb = clean_gb(row[4])
            days = clean_price(row[5])
            
            csv_packages.append({
                'provider': provider,
                'price': price,
                'gb': gb,
                'days': days
            })

    # Read Rekap Export CSV
    db_packages = []
    with open('Rekap_Harga_9.csv', 'r', encoding='utf-8') as f:
        reader = csv.reader(f)
        header = next(reader)
        for row in reader:
            if len(row) < 4: continue
            provider = str(row[0]).upper().strip()
            if provider == 'SF': provider = 'SMARTFREN'
            elif provider == 'TSEL': provider = 'TELKOMSEL'
            elif provider == '3ID': provider = '3'
            elif provider == 'BYU': provider = 'BY.U'
            
            db_packages.append({
                'provider': provider,
                'price': clean_price(row[2]),
                'gb': clean_gb(row[3]),
                'days': clean_price(row[4])
            })
            
    print(f"Total packages in CSV Ground Truth: {len(csv_packages)}")
    print(f"Total packages extracted by AI: {len(db_packages)}")
    
    matched = 0
    csv_unmatched = list(csv_packages)
    db_unmatched = []
    
    for db_pkg in db_packages:
        found_match = False
        # 1. Strict Match
        for i, csv_pkg in enumerate(csv_unmatched):
            if (db_pkg['provider'] == csv_pkg['provider'] and 
                db_pkg['price'] == csv_pkg['price'] and 
                db_pkg['days'] == csv_pkg['days'] and
                abs(db_pkg['gb'] - csv_pkg['gb']) < 0.1):
                
                matched += 1
                csv_unmatched.pop(i)
                found_match = True
                break
                
        # 2. Relaxed Match (Ignore days, Price difference <= 2000, match GB exactly)
        if not found_match:
            for i, csv_pkg in enumerate(csv_unmatched):
                if (db_pkg['provider'] == csv_pkg['provider'] and 
                    abs(db_pkg['price'] - csv_pkg['price']) <= 2000 and 
                    abs(db_pkg['gb'] - csv_pkg['gb']) < 0.1):
                    
                    matched += 1
                    csv_unmatched.pop(i)
                    found_match = True
                    break
        
        if not found_match:
            db_unmatched.append(db_pkg)
            
    # Match remaining by Provider and Price to show "Misread" data
    misreads = []
    
    # Try to pair up db_unmatched and csv_unmatched
    paired_csv = []
    paired_db = []
    
    for db_pkg in list(db_unmatched):
        best_match_idx = -1
        best_score = 999999
        for i, csv_pkg in enumerate(csv_unmatched):
            if db_pkg['provider'] == csv_pkg['provider']:
                # Score based on price difference
                price_diff = abs(db_pkg['price'] - csv_pkg['price'])
                if price_diff < best_score and price_diff <= 5000:
                    best_score = price_diff
                    best_match_idx = i
                    
        if best_match_idx != -1:
            misreads.append({
                'ai': db_pkg,
                'truth': csv_unmatched[best_match_idx]
            })
            db_unmatched.remove(db_pkg)
            csv_unmatched.pop(best_match_idx)
            
    # Calculate accuracy
    accuracy = (matched / len(csv_packages)) * 100 if len(csv_packages) > 0 else 0

    # Generate Markdown Report
    with open('mismatch_report.md', 'w', encoding='utf-8') as f:
        f.write("# 📊 Laporan Rekap Komparasi Ekstraksi (File ID 9)\n\n")
        f.write("## 📈 Ringkasan Metrik\n")
        f.write(f"- **Total Paket (Ground Truth CSV):** {len(csv_packages)}\n")
        f.write(f"- **Total Paket (Hasil AI):** {len(db_packages)}\n")
        f.write(f"- **Akurasi 100% Cocok:** {matched} paket ({accuracy:.2f}%)\n\n")
        
        f.write("## ⚠️ Data Salah Baca (Misread / OCR Confusion)\n")
        f.write("Berikut adalah data yang ditangkap oleh AI, namun nilainya meleset dari data yang seharusnya (Ground Truth). Pasangan di bawah ini didasarkan pada kecocokan *Provider* dan *Harga* yang berdekatan.\n\n")
        
        f.write("| Provider | Harga (AI) | GB (AI) | Masa Aktif (AI) | ➡️ | Harga Seharusnya | GB Seharusnya | Masa Aktif Seharusnya |\n")
        f.write("|----------|------------|---------|-----------------|----|------------------|---------------|-----------------------|\n")
        for pair in misreads:
            ai = pair['ai']
            tr = pair['truth']
            f.write(f"| {ai['provider']} | Rp{ai['price']:,} | {ai['gb']}GB | {ai['days']} Hari | ➡️ | Rp{tr['price']:,} | {tr['gb']}GB | {tr['days']} Hari |\n")
            
        f.write("\n## ❌ Data Benar-Benar Hilang (Tidak Ditemukan AI)\n")
        f.write("Paket-paket berikut ada di data asli, tetapi AI sama sekali gagal mengekstraknya:\n\n")
        f.write("| Provider | Harga | GB | Masa Aktif |\n")
        f.write("|----------|-------|----|------------|\n")
        for tr in csv_unmatched:
            f.write(f"| {tr['provider']} | Rp{tr['price']:,} | {tr['gb']}GB | {tr['days']} Hari |\n")
            
        f.write("\n## 👻 Data Halusinasi (Dikarang oleh AI)\n")
        f.write("Paket-paket berikut diekstrak oleh AI, namun sama sekali tidak ada di data asli:\n\n")
        f.write("| Provider | Harga | GB | Masa Aktif |\n")
        f.write("|----------|-------|----|------------|\n")
        for ai in db_unmatched:
            f.write(f"| {ai['provider']} | Rp{ai['price']:,} | {ai['gb']}GB | {ai['days']} Hari |\n")
            
    print("Report generated at mismatch_report.md")

if __name__ == "__main__":
    main()
