import sys
sys.stdout.reconfigure(encoding='utf-8')

with open(r'c:\xampp\htdocs\cenlearn\system\shared\live_class.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

for idx, line in enumerate(lines, 1):
    if 'live class' in line.lower():
        print(f"L{idx}: {line.strip()}")
