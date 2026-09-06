import os, sys
sys.stdout.reconfigure(encoding='utf-8')

root_dir = r'c:\xampp\htdocs\cenlearn\system'

for root, dirs, files in os.walk(root_dir):
    for f in files:
        if f.endswith('.php'):
            p = os.path.join(root, f)
            try:
                with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
                    lines = fh.readlines()
                for idx, line in enumerate(lines, 1):
                    if 'live class' in line.lower():
                        rel = os.path.relpath(p, root_dir)
                        print(f"{rel}:L{idx}: {line.strip()[:100]}")
            except Exception as e:
                pass
