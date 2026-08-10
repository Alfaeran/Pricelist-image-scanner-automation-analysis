import re

file_path = "d:\\pricelist-scanner-automation-dashboard\\scanner-app\\resources\\js\\Pages\\Scanner\\Index.vue"
with open(file_path, "r", encoding="utf-8") as f:
    lines = f.readlines()

start_idx = 2580
end_idx = 3500

for i in range(start_idx, min(end_idx, len(lines))):
    line = lines[i]
    
    # 1. Reverse background and wrapper classes
    line = line.replace('bg-secondary p-4 rounded-2xl text-white', 'bg-white p-4 rounded-2xl')
    line = line.replace('bg-secondary p-5 rounded-2xl text-white', 'bg-white p-5 rounded-2xl')
    line = line.replace('bg-secondary border border-secondary rounded-2xl text-white', 'bg-white border border-slate-200 rounded-2xl')
    line = line.replace('bg-secondary flex justify-between items-center', 'bg-white flex justify-between items-center')
    line = line.replace('p-4 bg-secondary shadow-xs text-white', 'p-4 bg-white shadow-xs')
    line = line.replace('border-white/20', 'border-slate-200')
    line = line.replace('text-white overflow-hidden', 'overflow-hidden') # From the mt-8 card where text-white was injected

    # 2. Reverse text colors
    # Caution: We must only do this for the main text, not for text inside buttons that should remain white.
    # We will simply revert text-white back to text-slate-800.
    line = line.replace('text-white/80', 'text-slate-500')
    line = line.replace('text-white/70', 'text-slate-400')
    line = line.replace('text-white/90', 'text-slate-600')
    
    # Reverting 'text-white' to 'text-slate-800' globally on these lines is dangerous if buttons use text-white.
    # But wait, my previous script changed text-white to text-secondary for the buttons!
    # Let's revert the buttons first so they don't get caught in the global replace, or we just rely on order.
    
    line = line.replace('bg-white text-secondary hover:bg-slate-100', 'bg-secondary text-white hover:bg-[#d90017]')
    line = line.replace('border-white', 'border-secondary')
    line = line.replace('ring-white', 'ring-secondary')
    
    # Now replace the remaining 'text-white' that aren't part of buttons
    # Buttons now have 'text-white' restored, so we don't want to break them.
    # In Tailwind, text-white usually stands alone. If it's a button, it's alongside bg-secondary.
    # Let's use regex to replace text-white only if the line doesn't contain bg-secondary.
    if 'bg-secondary' not in line:
        line = line.replace('text-white', 'text-slate-800')

    # 3. Reverse active tabs in Market Summarize
    # They were: activeSummaryTab === 'yield' ? 'bg-primary border-primary shadow-lg text-slate-900'
    # We want them to be secondary.
    line = line.replace("activeSummaryTab === 'yield' ? 'bg-primary border-primary shadow-lg text-slate-900'", "activeSummaryTab === 'yield' ? 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'")
    line = line.replace("activeSummaryTab === 'price' ? 'bg-primary border-primary shadow-lg text-slate-900'", "activeSummaryTab === 'price' ? 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'")
    line = line.replace("activeSummaryTab === 'quota' ? 'bg-primary border-primary shadow-lg text-slate-900'", "activeSummaryTab === 'quota' ? 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'")
    line = line.replace("activeSummaryTab === 'validity' ? 'bg-primary border-primary shadow-lg text-slate-900'", "activeSummaryTab === 'validity' ? 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'")

    lines[i] = line

with open(file_path, "w", encoding="utf-8") as f:
    f.writelines(lines)

print("Reverted results section cards successfully")
