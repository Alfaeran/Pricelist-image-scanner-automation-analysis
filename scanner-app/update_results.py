import re

file_path = "d:\\pricelist-scanner-automation-dashboard\\scanner-app\\resources\\js\\Pages\\Scanner\\Index.vue"
with open(file_path, "r", encoding="utf-8") as f:
    lines = f.readlines()

# The results section is roughly from line 2580 to 3500
# We'll apply the replacements to this specific slice.
start_idx = 2580
end_idx = 3500

for i in range(start_idx, min(end_idx, len(lines))):
    line = lines[i]
    
    # 1. Change card backgrounds
    line = line.replace('bg-primary p-4 rounded-2xl', 'bg-secondary p-4 rounded-2xl text-white')
    line = line.replace('bg-primary p-5 rounded-2xl', 'bg-secondary p-5 rounded-2xl text-white')
    line = line.replace('bg-primary border border-slate-900/10 rounded-2xl', 'bg-secondary border border-secondary rounded-2xl text-white')
    line = line.replace('bg-primary flex justify-between items-center', 'bg-secondary flex justify-between items-center')
    line = line.replace('p-4 bg-primary shadow-xs', 'p-4 bg-secondary shadow-xs text-white')
    line = line.replace('border-slate-900/10', 'border-white/20')

    # 2. Fix text colors inside the cards
    line = re.sub(r'text-slate-800( dark:text-slate-100)?', 'text-white', line)
    line = re.sub(r'text-slate-700( dark:text-slate-300)?', 'text-white', line)
    line = re.sub(r'text-slate-600( dark:text-slate-300)?', 'text-white/90', line)
    line = re.sub(r'text-slate-500( dark:text-slate-400)?', 'text-white/80', line)
    line = re.sub(r'text-slate-400( dark:text-slate-500)?', 'text-white/70', line)
    line = re.sub(r'text-blue-800( dark:text-blue-200)?', 'text-white', line)
    line = re.sub(r'text-blue-500( dark:text-blue-400)?', 'text-white', line)
    
    # Some buttons are secondary, if the card is secondary, the button should probably be white
    if 'bg-secondary' in line and 'hover:bg-[#d90017]' in line:
        line = line.replace('bg-secondary text-white hover:bg-[#d90017]', 'bg-white text-secondary hover:bg-slate-100')
        line = line.replace('border-secondary', 'border-white')
    if 'bg-secondary' in line and 'hover:bg-red-700' in line:
         line = line.replace('bg-secondary text-white hover:bg-red-700', 'bg-white text-secondary hover:bg-slate-100')
         line = line.replace('ring-secondary', 'ring-white')
         
    # Fix active tabs in Market Summarize
    # They were: 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'
    # Since the parent is secondary, let's make the active tab white text, but wait, the active tab was secondary.
    # Let's make the active tab primary (yellow) so it stands out against the red card!
    line = line.replace("activeSummaryTab === 'yield' ? 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'", "activeSummaryTab === 'yield' ? 'bg-primary border-primary shadow-lg text-slate-900'")
    line = line.replace("activeSummaryTab === 'price' ? 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'", "activeSummaryTab === 'price' ? 'bg-primary border-primary shadow-lg text-slate-900'")
    line = line.replace("activeSummaryTab === 'quota' ? 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'", "activeSummaryTab === 'quota' ? 'bg-primary border-primary shadow-lg text-slate-900'")
    line = line.replace("activeSummaryTab === 'validity' ? 'bg-secondary border-secondary shadow-lg shadow-secondary/30 text-white'", "activeSummaryTab === 'validity' ? 'bg-primary border-primary shadow-lg text-slate-900'")

    lines[i] = line

with open(file_path, "w", encoding="utf-8") as f:
    f.writelines(lines)

print("Results section cards updated successfully")
