from database import get_database
import pandas as pd

db = get_database()
pd.set_option('display.max_rows', None)
pd.set_option('display.width', 200)

# Check categories and data distribution
df = db.query("SELECT provider, category, COUNT(*) as cnt, AVG(price) as avg_price, AVG(gb) as avg_gb, AVG(yield_val) as avg_yield, MIN(days) as min_days, MAX(days) as max_days FROM package_history GROUP BY provider, category ORDER BY provider, category")
print("=== DATA DISTRIBUTION ===")
print(df.to_string(index=False))
print()

# Check AXIS provider
df2 = db.query("SELECT DISTINCT provider FROM package_history WHERE provider IN ('AXIS', 'SF')")
print("Has AXIS/SF:", df2['provider'].tolist() if not df2.empty else "NONE")

# Check columns
df3 = db.query("PRAGMA table_info(package_history)")
print("Columns:", df3['name'].tolist())

# Sample: TSEL packages under 50K
df4 = db.query("SELECT provider, gb, days, price, yield_val, category FROM package_history WHERE provider='TSEL' AND price < 50000 LIMIT 5")
print("\n=== TSEL under 50K (sample) ===")
print(df4.to_string(index=False))

# IM3 packages with days > 28
df5 = db.query("SELECT COUNT(*) as cnt FROM package_history WHERE provider='IM3' AND days > 28")
print(f"\n=== IM3 with days > 28: {df5['cnt'].iloc[0]} ===")

# Category 'MONTHLY 30-50K' check
df6 = db.query("SELECT COUNT(*) as cnt, AVG(price) as avg_price FROM package_history WHERE category='MONTHLY 30-50K'")
print(f"\n=== MONTHLY 30-50K: count={df6['cnt'].iloc[0]}, avg_price={df6['avg_price'].iloc[0]} ===")

# Sachet 3 hari for 3ID and AXIS
df7 = db.query("SELECT provider, COUNT(*) as cnt, AVG(yield_val) as avg_yield FROM package_history WHERE provider IN ('3ID', 'AXIS') AND category LIKE '%SACHET%3%' GROUP BY provider")
print("\n=== 3ID/AXIS Sachet 3 hari ===")
print(df7.to_string(index=False) if not df7.empty else "No data for sachet 3 hari comparison")

# Max kuota
df8 = db.query("SELECT provider, gb, days, price, category FROM package_history ORDER BY gb DESC LIMIT 3")
print("\n=== Top 3 kuota terbesar ===")
print(df8.to_string(index=False))

# Packets under 30K and >= 10GB
df9 = db.query("SELECT provider, gb, days, price FROM package_history WHERE price <= 30000 AND gb >= 10")
print(f"\n=== Price <= 30K AND gb >= 10: {len(df9)} rows ===")
if not df9.empty:
    print(df9.to_string(index=False))
