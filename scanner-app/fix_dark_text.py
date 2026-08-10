import re

file_path = "d:\\pricelist-scanner-automation-dashboard\\scanner-app\\resources\\js\\Pages\\Scanner\\Index.vue"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# Replace dark:text-slate-xxx with dark:text-white
content = re.sub(r'dark:text-slate-[345678]00', 'dark:text-white', content)
content = re.sub(r'dark:text-gray-[345678]00', 'dark:text-white', content)
# Also change dark:text-slate-100 or 200 to dark:text-white to ensure pure white as requested
content = re.sub(r'dark:text-slate-[12]00', 'dark:text-white', content)

# Check if there are any explicit text-slate-xxx without dark mode modifier on elements that have dark mode backgrounds.
# It's safer to just let the user see the dark:text-white replacements first, since text-slate-xxx without dark modifier
# would already be grey in dark mode. If the element has a dark background, we should add dark:text-white.
# But adding it automatically via regex is risky. Let's see if there are missing dark modifiers.

with open(file_path, "w", encoding="utf-8") as f:
    f.write(content)

print("Fixed dark text colors")
