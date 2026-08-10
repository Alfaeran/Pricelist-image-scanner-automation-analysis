import re

file_path = "d:\\pricelist-scanner-automation-dashboard\\scanner-app\\resources\\js\\Pages\\Scanner\\Index.vue"
with open(file_path, "r", encoding="utf-8") as f:
    lines = f.readlines()

new_lines = []
for i, line in enumerate(lines):
    # The sidebar and header go up to roughly line 2650.
    # We want to remove dark:text-white from the main content sections because they have a white background.
    if i > 2630:
        # Also remove ' dark:text-white' from dynamic classes that we manually added, e.g. <span class="... dark:text-white"
        # Just safely remove 'dark:text-white' globally from these lines.
        line = line.replace(' dark:text-white', '')
        line = line.replace('dark:text-white', '')
    new_lines.append(line)

with open(file_path, "w", encoding="utf-8") as f:
    f.writelines(new_lines)

print("Removed dark:text-white from section bodies")
