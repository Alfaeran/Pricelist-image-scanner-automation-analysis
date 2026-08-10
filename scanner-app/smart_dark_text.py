import re

file_path = "d:\\pricelist-scanner-automation-dashboard\\scanner-app\\resources\\js\\Pages\\Scanner\\Index.vue"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# We want to find all class attributes and inject dark:text-white if needed.
def replacer(match):
    class_str = match.group(1)
    
    # If it already has dark:text-white or similar, skip
    if 'dark:text-' in class_str:
        return f'class="{class_str}"'
        
    # Check if it has grey text
    if not re.search(r'text-(?:slate|gray|zinc|neutral)-(?:300|400|500|600|700|800|900)', class_str):
        return f'class="{class_str}"'
        
    # Check if it has a white background that stays white in dark mode
    # i.e., has bg-white but NO dark:bg-something
    if 'bg-white' in class_str and 'dark:bg-' not in class_str:
        return f'class="{class_str}"'
        
    # Inject dark:text-white
    # Just append it at the end
    return f'class="{class_str} dark:text-white"'

# Regex to match class="..."
content = re.sub(r'class="([^"]+)"', replacer, content)

# Also handle Vue dynamic classes conditionally like :class="['text-slate-500', ...]"
# But this might be too complex for a simple regex. We'll stick to static classes for now.

with open(file_path, "w", encoding="utf-8") as f:
    f.write(content)

print("Smartly injected dark:text-white")
