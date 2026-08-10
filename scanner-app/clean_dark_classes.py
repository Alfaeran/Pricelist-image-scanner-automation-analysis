import re

file_path = "d:\\pricelist-scanner-automation-dashboard\\scanner-app\\resources\\js\\Pages\\Scanner\\Index.vue"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# 1. Clean up dark mode backgrounds for primary
content = content.replace('bg-primary dark:bg-slate-900', 'bg-primary')
content = content.replace('bg-primary dark:bg-slate-800', 'bg-primary')
content = content.replace('bg-primary/90 dark:bg-slate-800/80', 'bg-primary/90')

# 2. Clean up dark mode for secondary buttons/elements
content = content.replace('bg-secondary dark:bg-green-600', 'bg-secondary')
content = content.replace('bg-secondary text-white dark:bg-green-600', 'bg-secondary text-white')
content = content.replace('text-secondary dark:text-green-600', 'text-secondary')
content = content.replace('text-secondary dark:text-green-500', 'text-secondary')
content = content.replace('shadow-secondary/25 dark:shadow-green-500/25', 'shadow-secondary/25')

# Also clean up secondary/10
content = content.replace('bg-secondary/10 dark:bg-green-500/10', 'bg-secondary/10')
content = content.replace('hover:bg-secondary/10 dark:hover:bg-green-500/10', 'hover:bg-secondary/10')
content = content.replace('hover:border-secondary dark:hover:border-green-500', 'hover:border-secondary')

with open(file_path, "w", encoding="utf-8") as f:
    f.write(content)

print("Cleaned up hardcoded dark classes")
